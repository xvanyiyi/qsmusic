<?php

declare(strict_types=1);

namespace Qsm\Qishui;

/**
 * CENC (cenc-aes-ctr) 解密器。
 *
 * 汽水音乐下发的 m4a 是标准 CENC 加密：
 *   stsd 里是 enca（音频加密样本描述），带 sinf/schi/tenc（ivsize=8 + kid）
 *   stbl 里有 senc（每样本 IV）、saiz/saio（辅助信息索引）
 *   样本数据按 AES-128-CTR 逐样本加密，key 由 device.node 的 decodeSpade(spade_a) 得到
 *
 * 解密策略：不改动文件长度。把 senc/saiz/saio 与原 sinf 原位改写成 free box，
 * enca 改成 mp4a，这样 mdat 偏移完全不变，所有 stco/stsz 继续有效，
 * 播放器看到的就是一个普通的未加密 m4a。
 */
final class Cenc
{
    /** @var array<int,array{type:string,off:int,size:int}> */
    private array $boxes = [];

    /**
     * @return array{samples:int,decrypted_bytes:int,bytes_written:int}
     */
    public static function decryptFile(string $src, string $dst, string $keyHex): array
    {
        $keyHex = strtolower(trim($keyHex));
        if (strlen($keyHex) !== 32 || !ctype_xdigit($keyHex)) {
            throw new \InvalidArgumentException('CENC key 必须是 32 位十六进制');
        }
        $key = hex2bin($keyHex);
        $data = file_get_contents($src);
        if ($data === false || $data === '') {
            throw new \RuntimeException('读取失败: ' . $src);
        }

        $dec = new self();

        // ---- 1. 定位关键 box ----
        $stbl = ['moov', 'trak', 'mdia', 'minf', 'stbl'];
        $senc = $dec->find($data, [...$stbl, 'senc']);
        if ($senc === null) {
            // 未加密，直接复制
            file_put_contents($dst, $data);
            return ['samples' => 0, 'decrypted_bytes' => 0, 'bytes_written' => strlen($data)];
        }

        // ---- 2. 每样本 IV ----
        $bodyStart = $senc['off'] + 8;
        $flags = unpack('N', substr($data, $bodyStart, 4))[1] & 0xFFFFFF;
        $count = unpack('N', substr($data, $bodyStart + 4, 4))[1];
        $ivs = [];
        $p = $bodyStart + 8;
        for ($i = 0; $i < $count; $i++) {
            $ivs[] = substr($data, $p, 8);
            $p += 8;
            if ($flags & 1) {
                $subs = unpack('n', substr($data, $p, 2))[1];
                $p += 2 + $subs * 6;
            }
        }

        // ---- 3. 样本尺寸 ----
        $stsz = $dec->find($data, [...$stbl, 'stsz']);
        if ($stsz === null) {
            throw new \RuntimeException('m4a 缺少 stsz');
        }
        $fixed = unpack('N', substr($data, $stsz['off'] + 12, 4))[1];
        $n = unpack('N', substr($data, $stsz['off'] + 16, 4))[1];
        if ($fixed > 0) {
            $sizes = array_fill(0, $n, $fixed);
        } else {
            $sizes = array_values(unpack('N' . $n, substr($data, $stsz['off'] + 20, 4 * $n)));
        }

        // ---- 4. chunk 偏移 ----
        $stco = $dec->find($data, [...$stbl, 'stco']);
        $co64 = false;
        if ($stco === null) {
            $stco = $dec->find($data, [...$stbl, 'co64']);
            $co64 = true;
        }
        if ($stco === null) {
            throw new \RuntimeException('m4a 缺少 stco/co64');
        }
        $nchunk = unpack('N', substr($data, $stco['off'] + 12, 4))[1];
        $chunks = [];
        $base = $stco['off'] + 16;
        for ($i = 0; $i < $nchunk; $i++) {
            if ($co64) {
                $hi = unpack('N', substr($data, $base + $i * 8, 4))[1];
                $lo = unpack('N', substr($data, $base + $i * 8 + 4, 4))[1];
                $chunks[] = $hi * 4294967296 + $lo;
            } else {
                $chunks[] = unpack('N', substr($data, $base + $i * 4, 4))[1];
            }
        }

        $stsc = $dec->find($data, [...$stbl, 'stsc']);
        if ($stsc === null) {
            throw new \RuntimeException('m4a 缺少 stsc');
        }
        $nstsc = unpack('N', substr($data, $stsc['off'] + 12, 4))[1];
        $entries = [];
        $base = $stsc['off'] + 16;
        for ($i = 0; $i < $nstsc; $i++) {
            $e = unpack('N3', substr($data, $base + $i * 12, 12));
            $entries[] = ['first' => $e[1], 'per' => $e[2]];
        }

        // ---- 5. 样本 → 文件偏移 ----
        $offsets = [];
        $si = 0;
        $total = count($sizes);
        foreach ($entries as $idx => $e) {
            $last = $entries[$idx + 1]['first'] ?? (count($chunks) + 1);
            for ($c = $e['first'] - 1; $c < $last - 1; $c++) {
                if (!isset($chunks[$c])) {
                    break;
                }
                $off = $chunks[$c];
                for ($k = 0; $k < $e['per']; $k++) {
                    if ($si >= $total) {
                        break 2;
                    }
                    $offsets[$si] = $off;
                    $off += $sizes[$si];
                    $si++;
                }
            }
        }

        // ---- 6. 逐样本解密 ----
        $decrypted = 0;
        $limit = min($total, count($ivs));
        for ($i = 0; $i < $limit; $i++) {
            if (!isset($offsets[$i])) {
                break;
            }
            $size = $sizes[$i];
            if ($size <= 0) {
                continue;
            }
            $seg = substr($data, $offsets[$i], $size);
            $iv = $ivs[$i] . str_repeat("\x00", 8);
            $plain = openssl_decrypt($seg, 'aes-128-ctr', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            if ($plain === false) {
                throw new \RuntimeException('AES-CTR 解密失败 @sample ' . $i);
            }
            $data = substr_replace($data, $plain, $offsets[$i], $size);
            $decrypted += $size;
        }

        // ---- 7. 抹掉加密元数据（等长改写，保持 mdat 偏移）----
        foreach (['senc', 'saiz', 'saio'] as $name) {
            $b = $dec->find($data, [...$stbl, $name]);
            if ($b !== null) {
                $data = $dec->blankBox($data, $b['off'], $b['size']);
            }
        }

        // stsd: enca -> 原始格式（sinf/frma 里的 4 字节 original format），内部 sinf -> free
        // AAC 是 mp4a，FLAC 是 fLaC，ALAC 是 alac；必须按 frma 还原，不能硬编码 mp4a。
        $stsd = $dec->find($data, [...$stbl, 'stsd']);
        if ($stsd !== null) {
            foreach ($dec->children($data, $stsd['off'] + 16, $stsd['off'] + $stsd['size']) as $entry) {
                if ($entry['type'] !== 'enca' && $entry['type'] !== 'encv') {
                    continue;
                }
                $audioHdr = $entry['type'] === 'enca' ? 28 : 78;
                $origFormat = '';
                $sinfBox = null;
                foreach ($dec->children($data, $entry['off'] + 8 + $audioHdr, $entry['off'] + $entry['size']) as $sub) {
                    if ($sub['type'] !== 'sinf') {
                        continue;
                    }
                    $sinfBox = $sub;
                    foreach ($dec->children($data, $sub['off'] + 8, $sub['off'] + $sub['size']) as $s2) {
                        if ($s2['type'] === 'frma') {
                            $origFormat = substr($data, $s2['off'] + 12, 4);
                        }
                    }
                }
                if ($origFormat === '' || trim($origFormat, "\x00") === '') {
                    $origFormat = $entry['type'] === 'enca' ? 'mp4a' : 'mp4v';
                }
                $data = substr_replace($data, $origFormat, $entry['off'] + 4, 4);
                if ($sinfBox !== null) {
                    $data = $dec->blankBox($data, $sinfBox['off'], $sinfBox['size']);
                }
            }
        }

        if (file_put_contents($dst, $data) === false) {
            throw new \RuntimeException('写入失败: ' . $dst);
        }

        return ['samples' => $limit, 'decrypted_bytes' => $decrypted, 'bytes_written' => strlen($data)];
    }

    /**
     * 快速判断一个 m4a 是否 CENC 加密。
     */
    public static function isEncrypted(string $file): bool
    {
        $fp = fopen($file, 'rb');
        if ($fp === false) {
            return false;
        }
        $data = fread($fp, 131072) ?: '';
        fclose($fp);
        return str_contains($data, 'enca') && str_contains($data, 'tenc');
    }

    private function blankBox(string $data, int $off, int $size): string
    {
        $head = pack('N', $size) . 'free';
        $body = str_repeat("\x00", max(0, $size - 8));
        return substr_replace($data, $head . $body, $off, $size);
    }

    /**
     * @return array<int,array{type:string,off:int,size:int}>
     */
    private function children(string $data, int $start, int $end): array
    {
        $out = [];
        $i = $start;
        $len = min($end, strlen($data));
        while ($i + 8 <= $len) {
            $size = unpack('N', substr($data, $i, 4))[1];
            $type = substr($data, $i + 4, 4);
            if ($size === 0) {
                $size = $len - $i;
            }
            if ($size < 8) {
                break;
            }
            $out[] = ['type' => $type, 'off' => $i, 'size' => $size];
            $i += $size;
        }
        return $out;
    }

    /**
     * 按路径查找 box，返回其头偏移与大小。
     *
     * @param array<int,string> $path
     * @return array{off:int,size:int}|null
     */
    private function find(string $data, array $path): ?array
    {
        $start = 0;
        $end = strlen($data);
        $found = null;
        foreach ($path as $name) {
            $found = null;
            foreach ($this->children($data, $start, $end) as $box) {
                if ($box['type'] === $name) {
                    $found = $box;
                    break;
                }
            }
            if ($found === null) {
                return null;
            }
            $start = $found['off'] + 8;
            $end = $found['off'] + $found['size'];
        }
        return $found;
    }
}
