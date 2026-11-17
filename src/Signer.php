<?php

declare(strict_types=1);

namespace Qsm;

use Qsm\Http\Client;

/**
 * 签名 sidecar 客户端。
 *
 * 汽水音乐的 /luna/pc/* 接口需要 X-Helios / X-Medusa 两个请求头，
 * 由官方 bdms.node 原生库对「完整 URL + 请求头」计算得出，纯 PHP 无法复刻。
 * 因此把这一步交给本地 Node sidecar（signer/server.js），PHP 只负责业务。
 *
 * 音频是 cenc-aes-ctr 加密的，key 由官方 device.node 的 decodeSpade() 解出，
 * 同样通过 sidecar 完成。
 */
final class Signer
{
    private Client $http;

    public function __construct(private string $base)
    {
        $this->http = new Client(60);
    }

    /** sidecar 是否就绪 */
    public function health(): array
    {
        $r = $this->http->request('GET', $this->base . '/health');
        $j = json_decode($r['body'], true);
        return is_array($j) ? $j : ['ok' => false, 'raw' => $r['body']];
    }

    public function isUp(): bool
    {
        try {
            return !empty($this->health()['ok']);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 通过 sidecar 发起已签名请求。
     *
     * @param array<string,string>|null $body  非 null 时按 JSON 发送
     * @param array<string,string>      $headers 附加请求头（cookie 等）
     * @param bool                      $common  是否注入 luna 公共参数；passport 类接口传 false
     * @param string|null               $form    form-urlencoded 原始请求体（优先于 $body）
     * @return array{status:int,headers:array<string,string>,body:string,setCookie:array<int,string>,url:string,json:array|null}
     */
    public function fetch(
        string $method,
        string $path,
        array $query = [],
        ?array $body = null,
        array $headers = [],
        bool $common = true,
        ?string $form = null
    ): array {
        $url = str_starts_with($path, 'http') ? $path : Config::API_BASE . $path;
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }
        if ($form !== null && !isset($headers['content-type'])) {
            $headers['content-type'] = 'application/x-www-form-urlencoded';
        }
        $payload = [
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $headers,
            'body' => $form ?? ($body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE)),
            'common' => $common,
        ];
        $r = $this->http->request(
            'POST',
            $this->base . '/fetch',
            ['content-type' => 'application/json'],
            json_encode($payload, JSON_UNESCAPED_UNICODE)
        );
        $j = json_decode($r['body'], true);
        if (!is_array($j) || empty($j['ok'])) {
            $msg = is_array($j) ? ($j['error'] ?? 'unknown') : $r['body'];
            throw new \RuntimeException('签名服务异常: ' . $msg);
        }
        $respBody = (string) ($j['body'] ?? '');
        return [
            'status' => (int) ($j['status'] ?? 0),
            'headers' => is_array($j['headers'] ?? null) ? $j['headers'] : [],
            'body' => $respBody,
            'setCookie' => is_array($j['setCookie'] ?? null) ? $j['setCookie'] : [],
            'url' => (string) ($j['url'] ?? $url),
            'json' => $respBody !== '' ? (json_decode($respBody, true) ?: null) : null,
        ];
    }

    /**
     * 解 spade_a：返回 16 字节十六进制 key。
     */
    public function decodeSpade(string $spadeA): string
    {
        $r = $this->http->request(
            'POST',
            $this->base . '/spade',
            ['content-type' => 'application/json'],
            json_encode(['spade_a' => $spadeA], JSON_UNESCAPED_UNICODE)
        );
        $j = json_decode($r['body'], true);
        if (!is_array($j) || empty($j['ok'])) {
            $msg = is_array($j) ? ($j['error'] ?? 'unknown') : $r['body'];
            throw new \RuntimeException('decodeSpade 失败: ' . $msg);
        }
        $key = (string) ($j['keyHex'] ?? '');
        if (strlen($key) !== 32 || !ctype_xdigit($key)) {
            throw new \RuntimeException('decodeSpade 返回非法 key: ' . $key);
        }
        return strtolower($key);
    }
}
