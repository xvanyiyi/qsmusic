<?php

declare(strict_types=1);

namespace Qsm\Qishui;

use Qsm\CookieJar;

/**
 * 从本机已登录的汽水音乐客户端里提取 cookie。
 *
 * Chromium 系客户端的 cookie 存法：
 *   Local State → os_crypt.encrypted_key（base64，前 5 字节是 "DPAPI"，其余是 DPAPI 密文）
 *   Network/Cookies（SQLite）→ encrypted_value，格式 v10/v11 + 12 字节 nonce + 密文 + 16 字节 tag
 *   算法 AES-256-GCM，key 由 DPAPI（当前用户）解出
 *
 * 这样不用扫码也能拿到会员账号的完整登录态。
 */
final class Importer
{
    public const DEFAULT_USER_DATA = '%APPDATA%\\SodaMusic';

    /** 常见的客户端数据目录候选 */
    public static function candidates(): array
    {
        $out = [];
        $appdata = getenv('APPDATA') ?: '';
        $local = getenv('LOCALAPPDATA') ?: '';
        foreach ([
            $appdata . '\\SodaMusic',
            $appdata . '\\QishuiMusic',
            $appdata . '\\LunaMusic',
            $local . '\\SodaMusic',
            'D:\\qishui\\Soda Music\\3.5.1\\data',
        ] as $p) {
            if ($p !== '' && is_dir($p)) {
                $out[] = $p;
            }
        }
        return $out;
    }

    /**
     * @param string|null $userData 客户端 userData 目录，null 时自动探测
     * @return array{cookies:array<string,string>,jar:CookieJar,source:string,count:int}
     */
    public static function import(?string $userData = null): array
    {
        $dirs = $userData !== null ? [$userData] : self::candidates();
        $errors = [];
        foreach ($dirs as $dir) {
            $localState = $dir . '\\Local State';
            $cookieDb = $dir . '\\Network\\Cookies';
            if (!is_file($localState) || !is_file($cookieDb)) {
                continue;
            }
            try {
                $key = self::osCryptKey($localState);
                $rows = self::readCookieDb($cookieDb);
                $jar = new CookieJar();
                $count = 0;
                foreach ($rows as $row) {
                    $val = $row['value'] !== ''
                        ? $row['value']
                        : self::decryptValue($row['encrypted_value'], $key);
                    if ($val === null || $val === '') {
                        continue;
                    }
                    $jar->set($row['name'], $val);
                    $count++;
                }
                if ($jar->hasSession()) {
                    return [
                        'cookies' => $jar->all(),
                        'jar' => $jar,
                        'source' => $dir,
                        'count' => $count,
                    ];
                }
                $errors[] = $dir . ' 里没有登录态（cookie 条数 ' . $count . '）';
            } catch (\Throwable $e) {
                $errors[] = $dir . ': ' . $e->getMessage();
            }
        }
        throw new \RuntimeException('未能从客户端提取 cookie：' . implode('; ', $errors));
    }

    /** 解出 OS Crypt 主密钥 */
    private static function osCryptKey(string $localStateFile): string
    {
        $j = json_decode((string) file_get_contents($localStateFile), true);
        $b64 = $j['os_crypt']['encrypted_key'] ?? '';
        if (!is_string($b64) || $b64 === '') {
            throw new \RuntimeException('Local State 缺少 os_crypt.encrypted_key');
        }
        $blob = base64_decode($b64, true);
        if ($blob === false || strlen($blob) < 8) {
            throw new \RuntimeException('encrypted_key 解码失败');
        }
        if (substr($blob, 0, 5) === 'DPAPI') {
            $blob = substr($blob, 5);
        }
        return self::dpapiUnprotect($blob);
    }

    /** 通过 PowerShell 调 DPAPI（当前用户作用域） */
    private static function dpapiUnprotect(string $blob): string
    {
        $b64 = base64_encode($blob);
        // base64 只含 [A-Za-z0-9+/=]，可安全内联进 PowerShell 命令
        $ps = 'Add-Type -AssemblyName System.Security; ' .
            '$b=[Convert]::FromBase64String(\'' . $b64 . '\'); ' .
            '$k=[System.Security.Cryptography.ProtectedData]::Unprotect($b,$null,' .
            '[System.Security.Cryptography.DataProtectionScope]::CurrentUser); ' .
            '[Convert]::ToBase64String($k)';
        $cmd = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "' . $ps . '"';
        $out = trim((string) shell_exec($cmd . ' 2>&1'));
        $raw = base64_decode($out, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new \RuntimeException('DPAPI 解密失败: ' . substr($out, 0, 300));
        }
        return $raw;
    }

    /**
     * 读取 cookie 表（先把库文件复制出来，避免与运行中的客户端抢锁）。
     *
     * @return array<int,array{host_key:string,name:string,value:string,encrypted_value:string,path:string,is_secure:int,is_httponly:int}>
     */
    private static function readCookieDb(string $dbFile): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'qsmck');
        if ($tmp === false) {
            throw new \RuntimeException('无法创建临时文件');
        }
        // 用 .NET 以共享读方式复制，绕过客户端的文件锁
        $sq = static fn (string $p): string => "'" . str_replace("'", "''", $p) . "'";
        $ps = '$src=[System.IO.File]::Open(' . $sq($dbFile) . ",'Open','Read','ReadWrite'); " .
            '$dst=[System.IO.File]::Create(' . $sq($tmp) . '); ' .
            '$src.CopyTo($dst); $dst.Close(); $src.Close()';
        $cmd = 'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "' . $ps . '"';
        shell_exec($cmd . ' 2>&1');

        if (!is_file($tmp) || filesize($tmp) === 0) {
            throw new \RuntimeException('无法复制 cookie 数据库（客户端可能正在写入）: ' . $dbFile);
        }

        if (!extension_loaded('sqlite3')) {
            @unlink($tmp);
            throw new \RuntimeException('PHP 未启用 sqlite3 扩展，无法读取 cookie 数据库');
        }

        $db = new \SQLite3($tmp, SQLITE3_OPEN_READONLY);
        $res = $db->query('SELECT host_key,name,value,encrypted_value,path,is_secure,is_httponly FROM cookies');
        $rows = [];
        while ($res !== false && ($r = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = [
                'host_key' => (string) $r['host_key'],
                'name' => (string) $r['name'],
                'value' => (string) $r['value'],
                'encrypted_value' => (string) $r['encrypted_value'],
                'path' => (string) $r['path'],
                'is_secure' => (int) $r['is_secure'],
                'is_httponly' => (int) $r['is_httponly'],
            ];
        }
        $db->close();
        @unlink($tmp);
        return $rows;
    }

    /** v10/v11: AES-256-GCM */
    private static function decryptValue(string $blob, string $key): ?string
    {
        if ($blob === '') {
            return null;
        }
        $prefix = substr($blob, 0, 3);
        if ($prefix !== 'v10' && $prefix !== 'v11') {
            // 老版本明文
            return $blob;
        }
        $payload = substr($blob, 3);
        if (strlen($payload) <= 12 + 16) {
            return null;
        }
        $nonce = substr($payload, 0, 12);
        $tag = substr($payload, -16);
        $ct = substr($payload, 12, strlen($payload) - 12 - 16);
        $plain = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag);
        return $plain === false ? null : $plain;
    }
}
