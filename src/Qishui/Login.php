<?php

declare(strict_types=1);

namespace Qsm\Qishui;

use Qsm\Config;
use Qsm\CookieJar;
use Qsm\Signer;

/**
 * 扫码登录。
 *
 * 流程（与客户端实现一致）：
 *   1. GET  /passport/web/get_qrcode/?next=<LOGIN_NEXT>
 *        → data.qrcode_index_url（就是二维码里要放的 URL）
 *        → data.token
 *   2. 用户用汽水音乐 App 扫码
 *   3. GET  /passport/web/check_qrconnect/?next=<LOGIN_NEXT>&token=<token>
 *        → data.status: new / scanned / confirmed / expired
 *        confirmed 时响应头 Set-Cookie 下发 sessionid 等登录态
 *   4. 拿 cookie 调 /luna/pc/me 校验，并记录会员类型
 *
 * 注意：passport 对新版本有额外风控（返回 error_code 4031「版本过低」），
 * 那是因为缺少 ticket_guard 客户端证书。此时用 Importer 从本机已登录客户端
 * 提取 cookie 更省事。
 */
final class Login
{
    public function __construct(
        private Signer $signer,
        private CookieJar $jar,
        private Api $api
    ) {
    }

    /**
     * 申请二维码。
     *
     * @return array{token:string,qr_content:string,index_url:string,raw:array<string,mixed>}
     */
    public function begin(string $next = Config::LOGIN_NEXT): array
    {
        // 与官方 account-sdk 对齐：GET + aid=6383 + is_new_login=1，且不注入 luna 公共参数
        $r = $this->signer->fetch('GET', '/passport/web/get_qrcode/', [
            'aid' => Config::PASSPORT_AID,
            'next' => $next,
            'need_logo' => 'false',
            'need_short_url' => 'false',
            'is_new_login' => '1',
        ], null, [
            'user-agent' => Config::userAgent(),
        ], false);
        if ($r['setCookie'] !== []) {
            $this->jar->mergeSetCookie($r['setCookie']);
        }
        $j = $r['json'] ?? [];
        $data = $j['data'] ?? [];
        $errCode = (int) ($data['error_code'] ?? 0);
        if ($errCode !== 0) {
            throw new \RuntimeException(sprintf(
                '申请二维码被拒（error_code=%d）：%s',
                $errCode,
                (string) ($data['description'] ?? $j['message'] ?? '未知')
            ));
        }
        $token = (string) ($data['token'] ?? '');
        $indexUrl = (string) ($data['qrcode_index_url'] ?? '');
        if ($token === '') {
            throw new \RuntimeException('二维码响应缺少 token: ' . json_encode($j, JSON_UNESCAPED_UNICODE));
        }
        $qr = $indexUrl !== ''
            ? $indexUrl
            : Config::SCAN_LOGIN_URL . '?' . http_build_query([
                'token' => $token,
                'os' => 'Windows',
                'computer_name' => gethostname() ?: 'PC',
            ]);

        return ['token' => $token, 'qr_content' => $qr, 'index_url' => $indexUrl, 'raw' => $j];
    }

    /**
     * 轮询一次扫码状态。
     *
     * @return array{status:string,confirmed:bool,raw:array<string,mixed>,user:array<string,mixed>|null}
     */
    public function poll(string $token, string $next = Config::LOGIN_NEXT): array
    {
        // 官方实现是 POST + form-urlencoded，不是 GET
        $form = http_build_query([
            'aid' => Config::PASSPORT_AID,
            'next' => $next,
            'need_logo' => 'false',
            'need_short_url' => 'false',
            'is_frontier' => 'false',
            'token' => $token,
            'is_new_login' => '1',
        ]);
        $r = $this->signer->fetch('POST', '/passport/web/check_qrconnect/', [], null, [
            'user-agent' => Config::userAgent(),
        ], false, $form);

        if ($r['setCookie'] !== []) {
            $this->jar->mergeSetCookie($r['setCookie']);
        }
        $j = $r['json'] ?? [];
        $data = $j['data'] ?? [];
        $status = (string) ($data['status'] ?? 'unknown');
        $confirmed = $status === 'confirmed' || $status === '2' || $status === 'success';

        $user = null;
        if ($confirmed) {
            $this->jar->save();
            try {
                $user = $this->refreshUser();
            } catch (\Throwable) {
                $user = null;
            }
        }
        return [
            'status' => $status,
            'confirmed' => $confirmed,
            'error_code' => (int) ($data['error_code'] ?? 0),
            'raw' => $j,
            'user' => $user,
        ];
    }

    /**
     * 阻塞轮询直到登录成功、过期或超时。
     *
     * @return array<string,mixed>
     */
    public function wait(string $token, int $timeout = 180, int $interval = 2, ?callable $onTick = null): array
    {
        $deadline = time() + $timeout;
        $last = null;
        while (time() < $deadline) {
            $st = $this->poll($token);
            $last = $st;
            if ($onTick !== null) {
                $onTick($st);
            }
            if ($st['confirmed']) {
                return $st;
            }
            if ($st['status'] === 'expired') {
                throw new \RuntimeException('二维码已过期');
            }
            if (($st['error_code'] ?? 0) === 2046) {
                throw new \RuntimeException('用户在手机上取消了登录');
            }
            sleep($interval);
        }
        throw new \RuntimeException('等待扫码超时: ' . json_encode($last['raw'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    /**
     * 用当前 cookie 拉用户资料 + 会员状态并回写。
     *
     * @return array<string,mixed>
     */
    public function refreshUser(): array
    {
        $j = $this->api->me();
        // /luna/pc/me → {status_code, status_info, my_info:{id,nickname,is_vip,vip_stage,...}, my_stats}
        $info = $j['my_info'] ?? ($j['user'] ?? ($j['data'] ?? []));
        if (!is_array($info)) {
            $info = [];
        }
        $uid = (string) ($info['id'] ?? ($info['uid'] ?? ($info['user_id'] ?? '')));
        $nick = (string) ($info['nickname'] ?? ($info['name'] ?? ($info['public_name'] ?? '')));
        $vipStage = (string) ($info['vip_stage'] ?? '');
        $vip = $info['vip_type'] ?? ($vipStage !== '' ? $vipStage : ($info['vip'] ?? ''));
        $isVip = !empty($info['is_vip']);
        foreach (['is_vip', 'is_vip_user', 'vip_valid', 'is_svip', 'is_musician'] as $k) {
            if ($k === 'is_musician') {
                continue;
            }
            if (!empty($info[$k])) {
                $isVip = true;
            }
        }
        if ($uid !== '') {
            $this->jar->setUid($uid);
        }
        $this->jar->save('', [
            'uid' => $uid,
            'nickname' => $nick,
            'vip_type' => is_scalar($vip) ? (string) $vip : $vipStage,
            'vip_stage' => $vipStage,
            'is_vip' => $isVip,
            'douyin_id' => (string) ($info['douyin_id'] ?? ''),
            'sec_uid' => (string) ($info['sec_uid'] ?? ''),
            'me_raw' => $j,
        ]);
        return [
            'uid' => $uid,
            'nickname' => $nick,
            'vip_type' => is_scalar($vip) ? (string) $vip : $vipStage,
            'vip_stage' => $vipStage,
            'is_vip' => $isVip,
            'raw' => $j,
        ];
    }
}
