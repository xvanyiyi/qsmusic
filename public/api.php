<?php

declare(strict_types=1);

/**
 * 汽水音乐 —— JSON 接口（单文件，纯 GET）。
 *
 * 访问：http://127.0.0.1:8899/api.php?action=...
 *
 * 统一返回：
 *   {"code":0,"msg":"ok","data":...}     成功
 *   {"code":4001,"msg":"...","data":null} 失败
 *
 * action：
 *   status   服务 / 登录态 / 会员状态
 *   search   q=关键词 [limit=20] [offset=0] [with_url=1]
 *   song     id=曲目ID [quality=档位]      → 歌曲信息 + 歌词 + 各档直链 + 解密 key
 *   lyric    id=曲目ID                     → 只返回 LRC
 *   url      id=曲目ID [quality=档位]      → 只返回下载链接
 *   stream   id=曲目ID [quality=档位]      → 直接返回可播放文件（支持 Range / 拖动）
 *
 * 说明：官方直链是 CENC 加密的（cenc-aes-ctr），直接下下来播放器放不了。
 *   所以每个档位同时给三样东西：
 *     url       原始加密直链（自己解的话用这个）
 *     key       16 字节 hex 的 CENC key（配 iv_size=8 的 CTR 解密）
 *     play_url  走本文件解密后的直链，任何播放器直接能播
 */

require __DIR__ . '/../src/bootstrap.php';

use Qsm\Config;
use Qsm\CookieJar;
use Qsm\Qishui\Audio;
use Qsm\Qishui\Cenc;

set_time_limit(0);
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

/* ============================ 输出helper ============================ */

function ok(mixed $data): never
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        ['code' => 0, 'msg' => 'ok', 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function err(string $msg, int $code = 4001, int $http = 200): never
{
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => null], JSON_UNESCAPED_UNICODE);
    exit;
}

function q(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function qi(string $key, int $default = 0): int
{
    $s = q($key, (string) $default);
    return ctype_digit($s) ? (int) $s : $default;
}

/** 拼接自身地址，用于生成 play_url */
function self_url(array $params): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8899');
    $path = (string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php');
    return ($https ? 'https' : 'http') . '://' . $host . $path . '?' . http_build_query($params);
}

/** 同级 login.php 的地址 */
function login_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8899');
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/api.php'))), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/login.php';
}

function ms(int $ms): string
{
    if ($ms <= 0) {
        return '00:00';
    }
    $s = intdiv($ms, 1000);
    return sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
}

/** 从 track_v2 结构里抽歌曲信息 */
function track_info(array $j): array
{
    $t = $j['track'] ?? ($j['track_v2'] ?? []);
    $name = (string) ($t['name'] ?? ($t['title'] ?? ''));
    if ($name === '' && isset($t['name_parts'])) {
        foreach ((array) $t['name_parts'] as $p) {
            $name .= is_array($p) ? (string) ($p['text'] ?? '') : (string) $p;
        }
    }
    $artists = [];
    foreach ((array) ($t['artists'] ?? []) as $a) {
        if (is_array($a) && !empty($a['name'])) {
            $artists[] = (string) $a['name'];
        }
    }
    $covers = $t['album']['url_cover']['urls'] ?? [];
    $cover = '';
    if (is_array($covers) && $covers !== []) {
        $cover = (string) reset($covers);
        // url_cover 是「前缀 + uri」两段式的，只取 urls[0] 会得到半个地址
        $uri = (string) ($t['album']['url_cover']['uri'] ?? '');
        if ($uri !== '' && !str_contains($cover, $uri)) {
            $cover .= ltrim($uri, '/');
        }
    }

    $lyric = '';
    if (isset($j['lyric']['content'])) {
        $lyric = (string) $j['lyric']['content'];
    } elseif (isset($j['lyric']['content_parts'])) {
        foreach ((array) $j['lyric']['content_parts'] as $p) {
            $lyric .= is_array($p) ? (string) ($p['text'] ?? '') : (string) $p;
        }
    }

    $label = is_array($t['label_info'] ?? null) ? $t['label_info'] : [];
    $rates = [];
    foreach ((array) ($t['bit_rates'] ?? []) as $br) {
        if (is_array($br) && isset($br['quality'])) {
            $rates[(string) $br['quality']] = [
                'quality' => (string) $br['quality'],
                'bitrate' => (int) ($br['br'] ?? 0),
                'size' => (int) ($br['size'] ?? 0),
            ];
        }
    }

    return [
        'id' => (string) ($t['id'] ?? ''),
        'name' => $name,
        'artists' => implode(' / ', $artists),
        'album' => (string) ($t['album']['name'] ?? ''),
        'album_id' => (string) ($t['album']['id'] ?? ''),
        'duration' => intdiv((int) ($t['duration'] ?? 0), 1000),
        'duration_ms' => (int) ($t['duration'] ?? 0),
        'duration_text' => ms((int) ($t['duration'] ?? 0)),
        'cover' => $cover,
        'vip_only' => !empty($label['only_vip_playable']) || !empty($label['only_vip_download']),
        'declared_qualities' => array_values($rates),
        'lyric' => $lyric,
    ];
}

/** 把 resolve 结果整理成对外的档位数组 */
function quality_list(array $resolved): array
{
    $out = [];
    foreach ((array) ($resolved['sources'] ?? []) as $s) {
        $out[] = [
            'quality' => (string) $s['quality'],
            'codec' => (string) $s['codec'],
            'container' => (string) $s['vtype'],
            'bitrate' => (int) $s['bitrate'],
            'size' => (int) $s['size'],
            'url' => (string) $s['main_url'],
            'backup_url' => (string) ($s['backup_url'] ?? ''),
            'encrypted' => (bool) $s['encrypted'],
            'key' => (string) ($s['key_hex'] ?? ''),
            'kid' => (string) ($s['kid'] ?? ''),
            'iv_size' => 8,
            'play_url' => self_url([
                'action' => 'stream',
                'id' => (string) ($resolved['track_id'] ?? ''),
                'quality' => (string) $s['quality'],
            ]),
        ];
    }
    return $out;
}

/* ============================ 路由 ============================ */

$action = q('action', 'status');
$account = preg_replace('/[^A-Za-z0-9_\-]/', '', q('account', 'current')) ?: 'current';

try {
    $env = qsm_boot($account);
} catch (\Throwable $e) {
    err('初始化失败：' . $e->getMessage(), 5001);
}

switch ($action) {

    /* ---------------- 状态 ---------------- */
    case 'status': {
        $jar = $env['jar'];
        $meta = [];
        $file = CookieJar::defaultFile($account);
        if (is_file($file)) {
            $meta = json_decode((string) @file_get_contents($file), true) ?: [];
        }
        $user = [];
        if ($jar->hasSession()) {
            try {
                $user = $env['login']->refreshUser();
            } catch (\Throwable $e) {
                $user = ['error' => $e->getMessage()];
            }
        }
        $health = [];
        try {
            $health = $env['signer']->health();
        } catch (\Throwable $e) {
            $health = ['ok' => false, 'error' => $e->getMessage()];
        }
        ok([
            'logged_in' => $jar->hasSession(),
            'uid' => $jar->uid(),
            'is_vip' => !empty($user['is_vip']),
            'vip_type' => (string) ($user['vip_type'] ?? ''),
            'vip_stage' => (string) ($user['vip_stage'] ?? ''),
            'nickname' => (string) ($user['nickname'] ?? ''),
            'error' => (string) ($user['error'] ?? ''),
            'signer' => [
                'ok' => !empty($health['ok']),
                'url' => Config::$signerUrl,
                'node' => (string) ($health['node'] ?? ''),
                'native' => (string) ($health['native'] ?? ''),
                'bdticket' => (bool) ($health['bdticket']['ready'] ?? false),
            ],
            'login_page' => login_url(),
        ]);
    }

    /* ---------------- 搜索 ---------------- */
    case 'search': {
        $kw = q('q');
        if ($kw === '') {
            err('缺少 q 参数');
        }
        $limit = max(1, min(50, qi('limit', 20)));
        $offset = max(0, qi('offset', 0));
        $rows = $env['api']->search($kw, 'track', '', $limit);
        $tracks = [];
        foreach ($rows as $t) {
            $artists = [];
            foreach ((array) ($t['artists'] ?? []) as $a) {
                if (!empty($a['name'])) {
                    $artists[] = (string) $a['name'];
                }
            }
            $name = (string) ($t['name'] ?? '');
            if ($name === '' && isset($t['name_parts'])) {
                foreach ((array) $t['name_parts'] as $p) {
                    $name .= is_array($p) ? (string) ($p['text'] ?? '') : (string) $p;
                }
            }
            $cover = (string) (($t['album']['url_cover']['urls'][0] ?? '') ?: '');
            $uri = (string) ($t['album']['url_cover']['uri'] ?? '');
            if ($cover !== '' && $uri !== '' && !str_contains($cover, $uri)) {
                $cover .= ltrim($uri, '/');
            }
            $item = [
                'id' => (string) ($t['id'] ?? ''),
                'name' => $name,
                'artists' => implode(' / ', $artists),
                'album' => (string) ($t['album']['name'] ?? ''),
                'duration' => intdiv((int) ($t['duration'] ?? 0), 1000),
                'duration_ms' => (int) ($t['duration'] ?? 0),
                'duration_text' => ms((int) ($t['duration'] ?? 0)),
                'cover' => $cover,
                'vip' => !empty($t['is_vip']),
                'song_url' => self_url(['action' => 'song', 'id' => (string) ($t['id'] ?? '')]),
            ];
            if (q('with_url') === '1' && $item['id'] !== '') {
                try {
                    $item['qualities'] = quality_list($env['audio']->resolve($item['id']));
                } catch (\Throwable $e) {
                    $item['qualities_error'] = $e->getMessage();
                }
            }
            $tracks[] = $item;
        }
        ok([
            'keyword' => $kw,
            'limit' => $limit,
            'offset' => $offset,
            'total' => count($tracks),
            'tracks' => $tracks,
        ]);
    }

    /* ---------------- 歌曲详情 ---------------- */
    case 'song':
    case 'lyric':
    case 'url': {
        $id = q('id');
        if ($id === '') {
            err('缺少 id 参数');
        }
        $detail = [];
        try {
            $detail = $env['api']->track($id);
        } catch (\Throwable $e) {
            err('获取曲目失败：' . $e->getMessage(), 5002);
        }
        $info = track_info($detail);

        if ($action === 'lyric') {
            ok(['id' => $id, 'lyric' => $info['lyric']]);
        }

        $resolved = [];
        try {
            $resolved = $env['audio']->resolve($id);
        } catch (\Throwable $e) {
            $resolved = ['playable' => false, 'sources' => [], 'error' => $e->getMessage()];
        }
        $qualities = quality_list($resolved);

        if ($action === 'url') {
            $want = q('quality');
            $pick = null;
            foreach ($qualities as $q1) {
                if ($want !== '' && $q1['quality'] === $want) {
                    $pick = $q1;
                    break;
                }
            }
            if ($pick === null && $qualities !== []) {
                $pick = $qualities[0];
            }
            if ($pick === null) {
                err('没有可用音源：' . (string) ($resolved['error'] ?? '未知原因'), 5003);
            }
            ok([
                'id' => $id,
                'name' => $info['name'],
                'artists' => $info['artists'],
                'quality' => $pick['quality'],
                'url' => $pick['url'],
                'backup_url' => $pick['backup_url'],
                'play_url' => $pick['play_url'],
                'key' => $pick['key'],
                'kid' => $pick['kid'],
                'iv_size' => 8,
                'encrypted' => $pick['encrypted'],
                'size' => $pick['size'],
                'bitrate' => $pick['bitrate'],
                'is_preview' => !empty($resolved['is_preview']),
                'preview_note' => (string) ($resolved['preview_note'] ?? ''),
            ]);
        }

        ok([
            'id' => $id,
            'name' => $info['name'],
            'artists' => $info['artists'],
            'album' => $info['album'],
            'album_id' => $info['album_id'],
            'duration' => $info['duration'],
            'duration_text' => $info['duration_text'],
            'cover' => $info['cover'],
            'vip_only' => $info['vip_only'],
            'lyric' => $info['lyric'],
            'logged_in' => $env['jar']->hasSession(),
            'is_preview' => !empty($resolved['is_preview']),
            'preview_note' => (string) ($resolved['preview_note'] ?? ''),
            'full_duration' => (int) ($resolved['full_duration_ms'] ?? 0),
            'actual_duration' => (int) ($resolved['actual_duration_ms'] ?? 0),
            'playable' => !empty($resolved['playable']),
            'error' => (string) ($resolved['error'] ?? ''),
            'qualities' => $qualities,
            'declared_qualities' => $info['declared_qualities'],
        ]);
    }

    /* ---------------- 解密后直出（可播放 / 可拖进度） ---------------- */
    case 'stream': {
        $id = q('id');
        if ($id === '') {
            err('缺少 id 参数');
        }
        $want = q('quality');
        try {
            $resolved = $env['audio']->resolve($id);
        } catch (\Throwable $e) {
            err('取音源失败：' . $e->getMessage(), 5002, 502);
        }
        if (empty($resolved['playable'])) {
            err('没有可用音源：' . (string) ($resolved['error'] ?? ''), 5003, 404);
        }
        $src = null;
        if ($want !== '') {
            foreach ($resolved['sources'] as $s) {
                if ($s['quality'] === $want) {
                    $src = $s;
                    break;
                }
            }
        }
        if ($src === null) {
            $src = Audio::pick($resolved, $want !== '' ? $want : null) ?? ($resolved['sources'][0] ?? null);
        }
        if ($src === null) {
            err('没有可用音源', 5003, 404);
        }

        $safeId = preg_replace('/[^0-9A-Za-z_\-]/', '', $id) ?: 'x';
        $qName = preg_replace('/[^a-z_]/', '', (string) $src['quality']) ?: 'q';
        $ext = $src['vtype'] !== '' ? $src['vtype'] : 'm4a';
        $cacheDir = dirname(__DIR__) . '/storage/cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0777, true);
        }
        $final = $cacheDir . '/' . $safeId . '_' . $qName . '.' . $ext;

        if (!is_file($final) || filesize($final) < 1024) {
            // 同一首歌并发请求时只解一次
            $lock = fopen($cacheDir . '/.' . $safeId . '_' . $qName . '.lock', 'c');
            if ($lock !== false) {
                flock($lock, LOCK_EX);
            }
            try {
                if (!is_file($final) || filesize($final) < 1024) {
                    $tmpEnc = $cacheDir . '/.' . $safeId . '_' . $qName . '.' . getmypid() . '.enc';
                    $tmpOut = $cacheDir . '/.' . $safeId . '_' . $qName . '.' . getmypid() . '.out';
                    $lastErr = null;
                    $got = false;
                    foreach (array_filter([(string) $src['main_url'], (string) ($src['backup_url'] ?? '')]) as $tryUrl) {
                        try {
                            $env['http']->download($tryUrl, $tmpEnc, [
                                'user-agent' => Config::userAgent(),
                                'referer' => 'https://www.qishui.com/',
                            ]);
                            $got = true;
                            break;
                        } catch (\Throwable $e) {
                            $lastErr = $e;
                        }
                    }
                    if (!$got) {
                        throw new \RuntimeException('下载失败：' . ($lastErr?->getMessage() ?? '未知'));
                    }
                    if (!empty($src['encrypted']) && (string) ($src['key_hex'] ?? '') !== '') {
                        Cenc::decryptFile($tmpEnc, $tmpOut, (string) $src['key_hex']);
                        @unlink($tmpEnc);
                    } else {
                        @rename($tmpEnc, $tmpOut);
                    }
                    @rename($tmpOut, $final);
                }
            } catch (\Throwable $e) {
                if ($lock !== false) {
                    flock($lock, LOCK_UN);
                    fclose($lock);
                }
                err('准备音源失败：' . $e->getMessage(), 5004, 500);
            }
            if ($lock !== false) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }

        // ---- 输出（支持 Range，播放器才能拖进度）----
        $size = (int) filesize($final);
        $mime = match (strtolower($ext)) {
            'flac' => 'audio/flac',
            'mp4', 'm4a' => 'audio/mp4',
            default => 'application/octet-stream',
        };
        $fn = sprintf('%s_%s.%s', $safeId, $qName, $ext);

        header('Content-Type: ' . $mime);
        header('Accept-Ranges: bytes');
        header('Content-Disposition: inline; filename="' . $fn . '"');

        $start = 0;
        $end = $size - 1;
        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min($size - 1, (int) $m[2]);
                }
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                exit;
            }
            http_response_code(206);
            header(sprintf('Content-Range: bytes %d-%d/%d', $start, $end, $size));
        }
        $len = $end - $start + 1;
        header('Content-Length: ' . $len);

        $fp = fopen($final, 'rb');
        if ($fp === false) {
            exit;
        }
        fseek($fp, $start);
        $left = $len;
        while ($left > 0 && !feof($fp)) {
            $chunk = fread($fp, (int) min(262144, $left));
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $left -= strlen($chunk);
            if (connection_aborted()) {
                break;
            }
        }
        fclose($fp);
        exit;
    }

    default:
        err('未知 action：' . $action);
}
