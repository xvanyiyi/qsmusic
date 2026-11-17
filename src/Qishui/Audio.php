<?php

declare(strict_types=1);

namespace Qsm\Qishui;

use Qsm\Config;

/**
 * 音源解析：把 track_id 变成「可下载的音频流」。
 *
 * 数据来自两处：
 *   1. /luna/pc/media-player → player_infos[].video_model（明文 CDN 直链 + encrypt_info.spade_a）
 *   2. media-player 给的 url_player_info（VOD GetPlayInfo）→ 含 key_seed / spade_a / kid / 每档清晰度
 *
 * key 由官方 device.node decodeSpade(spade_a) 解出，再交给 Cenc 解密。
 */
final class Audio
{
    public function __construct(private Api $api)
    {
    }

    /**
     * @return array{
     *   track_id:string,
     *   sources:array<int,array<string,mixed>>,
     *   playable:bool,
     *   raw:array<string,mixed>
     * }
     */
    public function resolve(string $trackId, string $mediaType = 'track'): array
    {
        // 曲目详情：拿到「完整时长 / 完整 vid / 全档位 size / 会员要求」，
        // 用来判定 media-player 下发的到底是整首还是试听片段。
        $detail = null;
        $trackInfo = [];
        try {
            $detail = $this->api->track($trackId);
            $trackInfo = is_array($detail['track'] ?? null) ? $detail['track'] : [];
        } catch (\Throwable) {
            $trackInfo = [];
        }
        $fullDurationMs = (int) ($trackInfo['duration'] ?? 0);
        $fullVid = (string) ($trackInfo['vid'] ?? '');
        $labelInfo = is_array($trackInfo['label_info'] ?? null) ? $trackInfo['label_info'] : [];
        $declaredRates = [];
        foreach (($trackInfo['bit_rates'] ?? []) as $br) {
            if (is_array($br) && isset($br['quality'])) {
                $declaredRates[(string) $br['quality']] = (int) ($br['size'] ?? 0);
            }
        }

        $j = $this->api->player($trackId, $mediaType);
        $infos = $j['player_infos'] ?? [];
        if ($infos === []) {
            $msg = $j['status_info']['status_msg'] ?? '无播放信息';
            return ['track_id' => $trackId, 'sources' => [], 'playable' => false, 'raw' => $j, 'error' => $msg];
        }

        $videoModel = null;
        $vodDetail = null;
        foreach ($infos as $info) {
            if (!empty($info['video_model'])) {
                $vm = is_string($info['video_model'])
                    ? json_decode($info['video_model'], true)
                    : $info['video_model'];
                if (is_array($vm)) {
                    $videoModel = $vm;
                    break;
                }
            }
        }

        // VOD 明细：拿到 key_seed / kid / 更细的音质列表
        foreach ($infos as $info) {
            if (!empty($info['url_player_info'])) {
                try {
                    $vodDetail = $this->api->playDetail((string) $info['url_player_info']);
                } catch (\Throwable) {
                    $vodDetail = null;
                }
                break;
            }
        }

        $vodMap = [];
        $vodData = $vodDetail['video_info']['data'] ?? null;
        if (is_array($vodData)) {
            foreach (($vodData['video_list'] ?? []) as $key => $item) {
                $meta = [
                    'file_id' => $item['file_id'] ?? '',
                    'size' => $item['size'] ?? 0,
                    'bitrate' => $item['bitrate'] ?? 0,
                    'quality' => $item['quality'] ?? 'unknown',
                    'vtype' => $item['vtype'] ?? '',
                    'codec' => $item['codec_type'] ?? '',
                    'encrypted' => (bool) ($item['encrypt'] ?? false),
                    'kid' => $item['kid'] ?? '',
                    'spade_a' => $item['spade_a'] ?? '',
                    'encryption_method' => $item['encryption_method'] ?? '',
                ];
                $vodMap[$meta['file_id']] = $meta;
                unset($key);
            }
        }

        $sources = [];
        $list = $videoModel['video_list'] ?? [];
        foreach ($list as $item) {
            $meta = $item['video_meta'] ?? [];
            $enc = $item['encrypt_info'] ?? [];
            $fileId = (string) ($meta['file_id'] ?? '');
            $extra = $vodMap[$fileId] ?? [];

            $spade = (string) ($enc['spade_a'] ?? ($extra['spade_a'] ?? ''));
            $kid = (string) ($enc['kid'] ?? ($extra['kid'] ?? ''));
            $encrypted = (bool) ($enc['encrypt'] ?? ($extra['encrypted'] ?? ($spade !== '')));

            $source = [
                'quality' => (string) ($meta['quality'] ?? ($extra['quality'] ?? 'unknown')),
                'vtype' => (string) ($meta['vtype'] ?? ($extra['vtype'] ?? '')),
                'codec' => (string) ($meta['codec_type'] ?? ($extra['codec'] ?? '')),
                'bitrate' => (int) ($meta['bitrate'] ?? ($extra['bitrate'] ?? 0)),
                'sample_rate' => (string) ($meta['audio_sample_rate'] ?? ''),
                'size' => (int) ($meta['size'] ?? ($extra['size'] ?? 0)),
                'file_id' => $fileId,
                'main_url' => (string) ($item['main_url'] ?? ''),
                'backup_url' => (string) ($item['backup_url'] ?? ''),
                'encrypted' => $encrypted,
                'encryption_method' => (string) ($enc['encryption_method'] ?? ($extra['encryption_method'] ?? '')),
                'kid' => $kid,
                'spade_a' => $spade,
                'key_hex' => '',
            ];

            if ($encrypted && $spade !== '') {
                try {
                    $source['key_hex'] = $this->api->signer()->decodeSpade($spade);
                } catch (\Throwable $e) {
                    $source['key_error'] = $e->getMessage();
                }
            }
            if ($source['main_url'] !== '') {
                $sources[] = $source;
            }
        }

        // 按音质优先级排序
        usort($sources, static function (array $a, array $b): int {
            $pa = array_search($a['quality'], Config::QUALITY_ORDER, true);
            $pb = array_search($b['quality'], Config::QUALITY_ORDER, true);
            $pa = $pa === false ? 99 : $pa;
            $pb = $pb === false ? 99 : $pb;
            if ($pa === $pb) {
                return $b['bitrate'] <=> $a['bitrate'];
            }
            return $pa <=> $pb;
        });

        // 判定试听：media-player 下发的 video_duration 明显短于曲目完整时长
        $actualDurationMs = (int) round(((float) ($videoModel['video_duration'] ?? 0)) * 1000);
        $isPreview = $fullDurationMs > 0
            && $actualDurationMs > 0
            && $actualDurationMs + 2000 < $fullDurationMs;

        return [
            'track_id' => $trackId,
            'sources' => $sources,
            'playable' => $sources !== [],
            'video_model_type' => $videoModel['video_model_type'] ?? null,
            'expire_at' => $infos[0]['expire_at'] ?? null,
            // 完整版元信息 + 试听判定
            'full_duration_ms' => $fullDurationMs,
            'full_vid' => $fullVid,
            'declared_rates' => $declaredRates,
            'label_info' => $labelInfo,
            'vip_only' => !empty($labelInfo['only_vip_playable']) || !empty($labelInfo['only_vip_download']),
            'actual_duration_ms' => $actualDurationMs,
            'is_preview' => $isPreview,
            'preview_note' => $isPreview
                ? sprintf(
                    '拿到的是试听片段（%.0f 秒 / 完整 %.0f 秒），解锁完整音源需要登录会员账号',
                    $actualDurationMs / 1000,
                    $fullDurationMs / 1000
                )
                : '',
            'raw' => $j,
        ];
    }

    /** 选一档：默认最高音质 */
    public static function pick(array $resolved, ?string $prefer = null): ?array
    {
        $sources = $resolved['sources'] ?? [];
        if ($sources === []) {
            return null;
        }
        if ($prefer !== null && $prefer !== '') {
            foreach ($sources as $s) {
                if (strcasecmp($s['quality'], $prefer) === 0) {
                    return $s;
                }
            }
        }
        return $sources[0];
    }
}
