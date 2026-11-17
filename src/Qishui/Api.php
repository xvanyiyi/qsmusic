<?php

declare(strict_types=1);

namespace Qsm\Qishui;

use Qsm\Config;
use Qsm\CookieJar;
use Qsm\Signer;

/**
 * 汽水音乐 PC 接口封装（api.qishui.com/luna/pc/*）。
 *
 * 所有请求都经 sidecar 签名；带 cookie 时自动附带登录态。
 */
final class Api
{
    public function __construct(
        private Signer $signer,
        private CookieJar $jar
    ) {
    }

    public function jar(): CookieJar
    {
        return $this->jar;
    }

    public function signer(): Signer
    {
        return $this->signer;
    }

    /** 构造请求头（cookie + 客户端标识头） */
    private function headers(): array
    {
        $h = [
            'user-agent' => Config::userAgent(),
            'x-luna-background-type' => 'foreground',
            'x-luna-is-background-req' => '0',
            'x-luna-is-local-user' => $this->jar->hasSession() ? '1' : '0',
        ];
        if (!$this->jar->isEmpty()) {
            $h['cookie'] = $this->jar->header();
        }
        return $h;
    }

    /**
     * 发起接口调用，自动合并 Set-Cookie。
     */
    public function call(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $q = array_merge(Config::commonParams(), $query);
        $r = $this->signer->fetch($method, $path, $q, $body, $this->headers());
        if ($r['setCookie'] !== []) {
            $this->jar->mergeSetCookie($r['setCookie']);
        }
        if ($r['status'] < 200 || $r['status'] >= 300) {
            throw new \RuntimeException(sprintf('接口 %s 返回 HTTP %d', $path, $r['status']));
        }
        $j = $r['json'];
        if (!is_array($j)) {
            throw new \RuntimeException('接口 ' . $path . ' 返回非 JSON: ' . substr($r['body'], 0, 200));
        }
        return $j;
    }

    /** 搜索。$type: track|all|artist|album|playlist */
    public function search(string $keyword, string $type = 'track', string $cursor = '', int $limit = 20): array
    {
        $j = $this->call('GET', '/luna/pc/search/' . $type, [
            'q' => $keyword,
            'cursor' => $cursor,
            'search_id' => '',
            'search_method' => 'keyword',
            'from_search_id' => '',
            'search_scene' => '',
            'scene_name' => '',
        ]);
        $this->throwIfError($j, '搜索');
        return $this->flattenTracks($j, $limit);
    }

    private function flattenTracks(array $j, int $limit): array
    {
        $tracks = [];
        foreach (($j['result_groups'] ?? []) as $group) {
            foreach (($group['data'] ?? []) as $item) {
                $entity = $item['entity'] ?? [];
                if (isset($entity['track'])) {
                    $tracks[] = $entity['track'];
                } elseif (isset($entity['media'])) {
                    $tracks[] = $entity['media'];
                }
                if (count($tracks) >= $limit) {
                    return $tracks;
                }
            }
        }
        return $tracks;
    }

    /** 曲目详情（含歌词、音质列表、会员要求） */
    public function track(string $trackId): array
    {
        $j = $this->call('GET', '/luna/pc/track_v2', ['track_id' => $trackId]);
        $this->throwIfError($j, '获取曲目');
        return $j;
    }

    /** 批量曲目：$type 可为 track / album / playlist 等 */
    public function tracks(array $ids, string $type = 'track'): array
    {
        $j = $this->call('POST', '/luna/pc/track_v2', [], [
            'media_type' => $type,
            'media_ids' => array_values($ids),
            'queue_type' => '',
        ]);
        $this->throwIfError($j, '批量获取曲目');
        return $j;
    }

    /** 播放信息（返回 player_infos，含 video_model 直链） */
    public function player(string $mediaId, string $mediaType = 'track'): array
    {
        $j = $this->call('GET', '/luna/pc/media-player', [
            'media_id' => $mediaId,
            'media_type' => $mediaType,
            'queue_type' => '',
            'enable_refresh_api' => '1',
            'enable_dash' => '1',
        ]);
        return $j;
    }

    /** 直接用 url_player_info 拉 VOD 明细（含 key_seed / spade_a / kid） */
    public function playDetail(string $urlPlayerInfo): array
    {
        $r = $this->signer->fetch('GET', $urlPlayerInfo, [], null, ['user-agent' => Config::userAgent()]);
        $j = $r['json'];
        if (!is_array($j)) {
            throw new \RuntimeException('VOD 明细返回非 JSON');
        }
        return $j;
    }

    /** 歌单详情 */
    public function playlist(string $id): array
    {
        $j = $this->call('GET', '/luna/pc/playlist/detail', ['playlist_id' => $id]);
        $this->throwIfError($j, '获取歌单');
        return $j;
    }

    /** 专辑详情 */
    public function album(string $id): array
    {
        $j = $this->call('GET', '/luna/pc/albums/' . rawurlencode($id));
        $this->throwIfError($j, '获取专辑');
        return $j;
    }

    /** 分享信息（分享 id → 资源） */
    public function shareInfo(string $shareId): array
    {
        $j = $this->call('GET', '/luna/pc/share_info', ['share_id' => $shareId]);
        $this->throwIfError($j, '获取分享信息');
        return $j;
    }

    /** 当前登录用户信息（会员状态在这里） */
    public function me(): array
    {
        $j = $this->call('GET', '/luna/pc/me');
        if (($j['status_code'] ?? 0) !== 0) {
            return $j;
        }
        return $j;
    }

    /** 会员/权益信息 */
    public function vipInfo(): array
    {
        return $this->call('POST', '/luna/pc/commerce/v2/commerce_info', [], ['scene' => 'vip_center']);
    }

    private function throwIfError(array $j, string $what): void
    {
        $code = $j['status_code'] ?? 0;
        if ($code !== 0) {
            $msg = $j['status_info']['status_msg'] ?? ('code=' . $code);
            throw new \RuntimeException($what . '失败: ' . $msg);
        }
    }
}
