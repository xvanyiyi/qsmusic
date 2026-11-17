<?php

declare(strict_types=1);

namespace Qsm;

/**
 * Cookie 存储：一个账号一个 JSON 文件，内含 cookie 字符串与会员快照。
 */
final class CookieJar
{
    /** @var array<string,string> */
    private array $cookies = [];

    private string $uid = '';

    public function __construct(private string $file = '')
    {
        if ($file !== '' && is_file($file)) {
            $this->loadFromFile($file);
        }
    }

    public static function defaultFile(string $name = 'current'): string
    {
        return Config::$cookieDir . '/' . preg_replace('/[^A-Za-z0-9_\-]/', '', $name) . '.json';
    }

    /** 从「name=value; name2=value2」形式的字符串载入 */
    public function loadString(string $raw): self
    {
        foreach (explode(';', $raw) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || !str_contains($pair, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $pair, 2);
            $this->cookies[trim($k)] = trim($v);
        }
        return $this;
    }

    private function loadFromFile(string $file): void
    {
        $raw = json_decode((string) file_get_contents($file), true);
        if (!is_array($raw)) {
            return;
        }
        $this->uid = (string) ($raw['uid'] ?? '');
        if (!empty($raw['cookies']) && is_array($raw['cookies'])) {
            foreach ($raw['cookies'] as $k => $v) {
                $this->cookies[(string) $k] = (string) $v;
            }
        } elseif (!empty($raw['cookie'])) {
            $this->loadString((string) $raw['cookie']);
        }
    }

    /** 合并 Set-Cookie 响应头 */
    public function mergeSetCookie(array $setCookies): self
    {
        foreach ($setCookies as $line) {
            $first = explode(';', (string) $line)[0];
            if (!str_contains($first, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $first, 2);
            $k = trim($k);
            $v = trim($v);
            if ($v === '' || preg_match('/Max-Age=0/i', (string) $line)) {
                unset($this->cookies[$k]);
                continue;
            }
            $this->cookies[$k] = $v;
        }
        return $this;
    }

    public function set(string $name, string $value): self
    {
        $this->cookies[$name] = $value;
        return $this;
    }

    public function get(string $name): string
    {
        return $this->cookies[$name] ?? '';
    }

    /** @return array<string,string> */
    public function all(): array
    {
        return $this->cookies;
    }

    public function header(): string
    {
        $parts = [];
        foreach ($this->cookies as $k => $v) {
            $parts[] = $k . '=' . $v;
        }
        return implode('; ', $parts);
    }

    public function isEmpty(): bool
    {
        return $this->cookies === [];
    }

    /** 关键登录态是否存在 */
    public function hasSession(): bool
    {
        return $this->get('sessionid') !== '' || $this->get('sessionid_ss') !== '';
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function setUid(string $uid): self
    {
        $this->uid = $uid;
        return $this;
    }

    /** 落盘 */
    public function save(string $file = '', array $extra = []): string
    {
        $file = $file !== '' ? $file : ($this->file !== '' ? $this->file : self::defaultFile());
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $data = array_merge([
            'uid' => $this->uid,
            'cookie' => $this->header(),
            'cookies' => $this->cookies,
            'updated_at' => date('c'),
        ], $extra);
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->file = $file;
        return $file;
    }

    public function path(): string
    {
        return $this->file;
    }

    /** 列出已保存的账号 */
    public static function list(): array
    {
        $out = [];
        foreach (glob(Config::$cookieDir . '/*.json') ?: [] as $f) {
            $raw = json_decode((string) file_get_contents($f), true);
            if (!is_array($raw)) {
                continue;
            }
            $out[] = [
                'file' => $f,
                'name' => basename($f, '.json'),
                'uid' => $raw['uid'] ?? '',
                'nickname' => $raw['nickname'] ?? '',
                'vip' => $raw['vip_type'] ?? '',
                'updated_at' => $raw['updated_at'] ?? '',
            ];
        }
        usort($out, static fn ($a, $b) => strcmp((string) $b['updated_at'], (string) $a['updated_at']));
        return $out;
    }
}
