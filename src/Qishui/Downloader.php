<?php

declare(strict_types=1);

namespace Qsm\Qishui;

use Qsm\Config;
use Qsm\Http\Client;

/**
 * 下载器：元数据 + 音源 + 解密 + 歌词，落盘成完整的一套文件。
 */
final class Downloader
{
    public function __construct(
        private Api $api,
        private Audio $audio,
        private Client $http
    ) {
    }

    /**
     * @param array{quality?:string,dir?:string,lyric?:bool,raw?:bool} $opts
     * @return array<string,mixed>
     */
    public function download(string $trackId, array $opts = []): array
    {
        $dir = $opts['dir'] ?? Config::$downloadDir;
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建下载目录: ' . $dir);
        }

        // ---- 元数据 ----
        $meta = [];
        try {
            $tj = $this->api->track($trackId);
            $meta = $this->extractMeta($tj);
        } catch (\Throwable $e) {
            $meta = ['title' => $trackId, 'artists' => '', 'album' => '', 'cover' => '', 'lyric' => '', 'error' => $e->getMessage()];
        }

        // ---- 音源 ----
        $resolved = $this->audio->resolve($trackId);
        if (!$resolved['playable']) {
            throw new \RuntimeException('无法播放: ' . ($resolved['error'] ?? '没有可用音源'));
        }
        $source = Audio::pick($resolved, $opts['quality'] ?? null);
        if ($source === null) {
            throw new \RuntimeException('没有可用音源');
        }

        // ---- 命名 ----
        $base = $this->safeName($meta['title'], $meta['artists']);
        $ext = $source['vtype'] !== '' ? $source['vtype'] : 'm4a';
        $encFile = $dir . '/' . $base . '.enc.' . $ext;
        $outFile = $dir . '/' . $base . '.' . $ext;

        // ---- 下载 ----
        $u = $source['main_url'];
        $backup = (string) ($source['backup_url'] ?? '');
        $err = null;
        foreach (array_filter([$u, $backup]) as $tryUrl) {
            try {
                $this->http->download($tryUrl, $encFile, [
                    'user-agent' => Config::userAgent(),
                    'referer' => 'https://www.qishui.com/',
                ]);
                $err = null;
                break;
            } catch (\Throwable $e) {
                $err = $e;
            }
        }
        if ($err !== null) {
            throw new \RuntimeException('下载失败: ' . $err->getMessage());
        }

        $size = (int) filesize($encFile);
        $result = [
            'track_id' => $trackId,
            'title' => $meta['title'],
            'artists' => $meta['artists'],
            'album' => $meta['album'],
            'quality' => $source['quality'],
            'bitrate' => $source['bitrate'],
            'encrypted' => $source['encrypted'],
            'key_hex' => $source['key_hex'] ?? '',
            'is_preview' => !empty($resolved['is_preview']),
            'preview_note' => (string) ($resolved['preview_note'] ?? ''),
            'full_duration_ms' => (int) ($resolved['full_duration_ms'] ?? 0),
            'actual_duration_ms' => (int) ($resolved['actual_duration_ms'] ?? 0),
            'vip_only' => !empty($resolved['vip_only']),
            'enc_file' => $encFile,
            'file' => $encFile,
            'bytes' => $size,
            'lyric_file' => '',
            'meta_file' => '',
        ];

        // ---- 解密 ----
        if ($source['encrypted']) {
            if (($source['key_hex'] ?? '') === '') {
                $result['decrypt_error'] = $source['key_error'] ?? '缺少 key';
                $result['file'] = $encFile;
            } else {
                $dec = Cenc::decryptFile($encFile, $outFile, $source['key_hex']);
                @unlink($encFile);
                $result['file'] = $outFile;
                $result['bytes'] = (int) filesize($outFile);
                $result['decrypt'] = $dec;
            }
        } else {
            @rename($encFile, $outFile);
            $result['file'] = $outFile;
            $result['bytes'] = (int) filesize($outFile);
        }

        // ---- 歌词 ----
        if (($opts['lyric'] ?? true) && $meta['lyric'] !== '') {
            $lrc = $dir . '/' . $base . '.lrc';
            file_put_contents($lrc, $meta['lyric']);
            $result['lyric_file'] = $lrc;
        }

        // ---- 元数据 ----
        $mj = $dir . '/' . $base . '.json';
        file_put_contents($mj, json_encode(array_merge($result, $meta), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $result['meta_file'] = $mj;

        return $result;
    }

    /**
     * @return array{title:string,artists:string,album:string,cover:string,duration:int,lyric:string,track_id:string}
     */
    private function extractMeta(array $j): array
    {
        $track = $j['track'] ?? ($j['track_v2'] ?? []);
        $title = (string) ($track['name'] ?? ($track['title'] ?? ''));
        if ($title === '') {
            foreach (($track['name_parts'] ?? []) as $part) {
                if (is_string($part)) {
                    $title .= $part;
                } elseif (is_array($part)) {
                    $title .= (string) ($part['text'] ?? '');
                }
            }
        }
        $artists = [];
        foreach (($track['artists'] ?? []) as $a) {
            if (is_array($a) && !empty($a['name'])) {
                $artists[] = (string) $a['name'];
            }
        }
        $album = (string) ($track['album']['name'] ?? '');
        $cover = '';
        $covers = $track['album']['url_cover']['urls'] ?? [];
        if (is_array($covers) && $covers !== []) {
            $cover = (string) reset($covers);
        }
        $lyric = '';
        if (isset($j['lyric']['content'])) {
            $lyric = (string) $j['lyric']['content'];
        } elseif (isset($j['lyric']['content_parts'])) {
            foreach ($j['lyric']['content_parts'] as $p) {
                $lyric .= is_array($p) ? (string) ($p['text'] ?? '') : (string) $p;
            }
        }
        return [
            'track_id' => (string) ($track['id'] ?? ''),
            'title' => $title !== '' ? $title : '未命名',
            'artists' => implode(' / ', $artists),
            'album' => $album,
            'cover' => $cover,
            'duration' => (int) ($track['duration'] ?? 0),
            'lyric' => $lyric,
        ];
    }

    private function safeName(string $title, string $artists): string
    {
        $name = $artists !== '' ? $artists . ' - ' . $title : $title;
        $name = preg_replace('#[\\\\/:*?"<>|\r\n\t]+#u', '_', $name) ?? $name;
        $name = trim($name, " ._");
        if ($name === '') {
            $name = 'track_' . time();
        }
        if (mb_strlen($name) > 120) {
            $name = mb_substr($name, 0, 120);
        }
        return $name;
    }
}
