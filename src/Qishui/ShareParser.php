<?php

declare(strict_types=1);

namespace Qsm\Qishui;

/**
 * 分享链接 / 文本 → 曲目或资源标识。
 *
 * 支持的输入形态：
 *   https://qishui.douyin.com/s/XXXXXXXX/        （App 分享短链）
 *   https://music.douyin.com/qishui/share/xxx
 *   https://www.qishui.com/share?track_id=123
 *   含 track_id= / media_id= 的任意链接
 *   纯数字曲目 ID
 *   「歌名 - 歌手」自由文本（回落到搜索）
 */
final class ShareParser
{
    public function __construct(private Api $api, private \Qsm\Http\Client $http)
    {
    }

    /**
     * @return array{type:string,value:string,raw:string,note:string}
     *   type: track_id | share_id | keyword | playlist_id | album_id
     */
    public function parse(string $input): array
    {
        $raw = trim($input);
        if ($raw === '') {
            throw new \InvalidArgumentException('输入为空');
        }

        // 1. 纯数字 ID
        if (preg_match('/^\d{10,25}$/', $raw) === 1) {
            return ['type' => 'track_id', 'value' => $raw, 'raw' => $raw, 'note' => '按曲目 ID 处理'];
        }

        // 2. 链接中的显式参数
        if (preg_match('#https?://[^\s]+#u', $raw, $m) === 1) {
            $url = $m[0];
            foreach (['track_id', 'media_id', 'item_id'] as $param) {
                if (preg_match('/[?&]' . $param . '=(\d{10,25})/', $url, $mm) === 1) {
                    return ['type' => 'track_id', 'value' => $mm[1], 'raw' => $url, 'note' => '来自 URL 参数 ' . $param];
                }
            }
            if (preg_match('/[?&]playlist_id=(\d{10,25})/', $url, $mm) === 1) {
                return ['type' => 'playlist_id', 'value' => $mm[1], 'raw' => $url, 'note' => '歌单链接'];
            }
            if (preg_match('/[?&]album_id=(\d{10,25})/', $url, $mm) === 1) {
                return ['type' => 'album_id', 'value' => $mm[1], 'raw' => $url, 'note' => '专辑链接'];
            }

            // 3. 短链：跟随重定向，从落地 URL 里找 ID
            $final = $this->follow($url);
            if ($final !== null && $final !== $url) {
                if (preg_match('/(\d{19})/', $final, $mm) === 1) {
                    return ['type' => 'track_id', 'value' => $mm[1], 'raw' => $url, 'note' => '短链跳转解析: ' . $final];
                }
            }

            // 4. /s/xxxx 形式的分享码
            if (preg_match('#/s/([A-Za-z0-9_\-]+)#', $url, $mm) === 1) {
                return ['type' => 'share_id', 'value' => $mm[1], 'raw' => $url, 'note' => '分享码'];
            }
            if (preg_match('/(\d{19})/', $url, $mm) === 1) {
                return ['type' => 'track_id', 'value' => $mm[1], 'raw' => $url, 'note' => 'URL 中的长数字串'];
            }
        }

        // 5. 歌曲标题之类的自由文本 → 搜索
        return ['type' => 'keyword', 'value' => $raw, 'raw' => $raw, 'note' => '按关键词搜索'];
    }

    /** 跟随短链，返回最终 URL */
    private function follow(string $url): ?string
    {
        try {
            $r = $this->http->request('GET', $url, [
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/131.0 Safari/537.36',
            ]);
            // curl 已跟随重定向，response 里没有最终 URL，退一步从 HTML 里找曲目链接
            if (preg_match('#https?://[^"\'\s>]*?(?:track|media)[^"\'\s>]*?(\d{19})#', $r['body'], $m) === 1) {
                return 'track:' . $m[1];
            }
            if (preg_match('#"(?:track_id|trackId)"\s*:\s*"?(\d{15,25})#', $r['body'], $m) === 1) {
                return 'track:' . $m[1];
            }
            if (preg_match('#(\d{19})#', $r['body'], $m) === 1) {
                return 'track:' . $m[1];
            }
        } catch (\Throwable) {
        }
        return null;
    }

    /**
     * 把解析结果落到具体曲目 ID 列表。
     *
     * @return array<int,string>
     */
    public function resolveToTrackIds(string $input, int $limit = 1): array
    {
        $p = $this->parse($input);
        switch ($p['type']) {
            case 'track_id':
                return [$p['value']];
            case 'playlist_id':
                $j = $this->api->playlist($p['value']);
                return array_slice($this->collectIds($j, 'playlist'), 0, max(1, $limit));
            case 'album_id':
                $j = $this->api->album($p['value']);
                return array_slice($this->collectIds($j, 'album'), 0, max(1, $limit));
            case 'share_id':
                $j = $this->api->shareInfo($p['value']);
                $ids = $this->collectIds($j, 'share');
                if ($ids !== []) {
                    return array_slice($ids, 0, max(1, $limit));
                }
                throw new \RuntimeException('分享信息里没有曲目: ' . json_encode($j, JSON_UNESCAPED_UNICODE));
            case 'keyword':
            default:
                $list = $this->api->search($p['value'], 'track', '', max(1, $limit));
                $ids = [];
                foreach ($list as $t) {
                    $id = (string) ($t['id'] ?? ($t['track_id'] ?? ''));
                    if ($id !== '') {
                        $ids[] = $id;
                    }
                }
                if ($ids === []) {
                    throw new \RuntimeException('搜索无结果: ' . $p['value']);
                }
                return $ids;
        }
    }

    /** 从任意结构里挖出所有疑似 track id */
    private function collectIds(array $node, string $ctx, int $depth = 0): array
    {
        $ids = [];
        if ($depth > 8) {
            return $ids;
        }
        if (isset($node['track']['id'])) {
            $ids[] = (string) $node['track']['id'];
        }
        if (isset($node['id']) && in_array($ctx, ['playlist', 'album', 'share'], true) && is_string($node['id']) && preg_match('/^\d{10,25}$/', $node['id']) === 1) {
            // 顶层资源自身 id，跳过
        }
        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $ids = array_merge($ids, $this->collectIds($v, (string) $k, $depth + 1));
            } elseif ((string) $k === 'track_id' && is_scalar($v) && preg_match('/^\d{10,25}$/', (string) $v) === 1) {
                $ids[] = (string) $v;
            }
        }
        return array_values(array_unique($ids));
    }
}
