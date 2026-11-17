<?php

declare(strict_types=1);

namespace Qsm\Http;

/**
 * 极简 curl 封装：JSON 请求 / 流式下载 / Range 断点。
 */
final class Client
{
    private int $timeout;

    public function __construct(int $timeout = 30)
    {
        $this->timeout = $timeout;
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,headers:array<string,string>,body:string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($url);
        $respHeaders = [];
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        $h[] = 'Expect:';

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$respHeaders): int {
                $len = strlen($line);
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $name = strtolower(trim(substr($line, 0, $pos)));
                    $value = trim(substr($line, $pos + 1));
                    $respHeaders[$name] = isset($respHeaders[$name])
                        ? $respHeaders[$name] . ', ' . $value
                        : $value;
                }
                return $len;
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            throw new \RuntimeException('HTTP 请求失败: ' . $err . ' -> ' . $url);
        }
        return ['status' => $status, 'headers' => $respHeaders, 'body' => (string) $out];
    }

    /**
     * 下载到文件，返回写入字节数。
     *
     * @param array<string,string> $headers
     */
    public function download(string $url, string $target, array $headers = [], ?callable $progress = null): int
    {
        $tmp = $target . '.part';
        $fp = fopen($tmp, 'wb');
        if ($fp === false) {
            throw new \RuntimeException('无法写入: ' . $tmp);
        }
        $ch = curl_init($url);
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 1800,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_NOPROGRESS => $progress === null,
            CURLOPT_PROGRESSFUNCTION => $progress === null
                ? null
                : static function ($ch, int $dlTotal, int $dlNow) use ($progress): int {
                    $progress($dlTotal, $dlNow);
                    return 0;
                },
        ]);
        $ok = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        fclose($fp);

        if ($ok === false || $status >= 400) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('下载失败 (HTTP %d) %s', $status, $err));
        }
        $size = (int) filesize($tmp);
        if (!@rename($tmp, $target)) {
            throw new \RuntimeException('无法重命名临时文件');
        }
        return $size;
    }
}
