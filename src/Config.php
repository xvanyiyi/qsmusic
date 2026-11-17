<?php

declare(strict_types=1);

namespace Qsm;

/**
 * 全局配置：设备指纹、应用版本、目录、签名服务地址。
 *
 * 这些值决定请求能否通过服务端校验，务必与真实客户端形态一致。
 */
final class Config
{
    /** 汽水音乐 PC 端 aid */
    public const APP_ID = '386088';

    /** 应用版本（UA 与 query 都会带上） */
    public const APP_VERSION = '3.5.1';

    /** 版本号：服务端用它判断「客户端是否过旧」 */
    public const VERSION_CODE = '3050100';

    /** tron build id（UA 括号内） */
    public const BUILD_ID = '3050100';

    /** 签名 sidecar 默认地址 */
    public const SIGNER_URL = 'http://127.0.0.1:8791';

    /** 扫码登录完成后的跳转地址 */
    public const LOGIN_NEXT = 'https://luna-pc.bytedance.net/login';

    /**
     * passport（扫码登录）用的 aid。
     * 业务接口用 APP_ID=386088，但登录走抖音统一账号体系，官方 account-sdk
     * 里写死「请求参数统一使用 6383」，见 @byted/douyin-login-new 的 api/index.js。
     */
    public const PASSPORT_AID = '6383';

    /** 二维码实际扫描目标前缀 */
    public const SCAN_LOGIN_URL = 'https://bff-pc.qishui.com/light/invoke/scan_login';

    public const API_BASE = 'https://api.qishui.com';

    /** 音质偏好顺序（越高越优先） */
    public const QUALITY_ORDER = ['lossless', 'flac', 'hires', 'excellent', 'higher', 'medium', 'standard', 'unknown'];

    public static string $root = __DIR__ . '/..';

    public static string $storage = __DIR__ . '/../storage';

    public static string $downloadDir = __DIR__ . '/../storage/downloads';

    public static string $cookieDir = __DIR__ . '/../storage/cookies';

    public static string $signerUrl = self::SIGNER_URL;

    /** 读取可选的本地覆盖配置 */
    public static function boot(): void
    {
        $file = self::$root . '/config/local.php';
        if (is_file($file)) {
            $cfg = require $file;
            if (is_array($cfg)) {
                if (!empty($cfg['signer_url'])) {
                    self::$signerUrl = rtrim((string) $cfg['signer_url'], '/');
                }
                if (!empty($cfg['download_dir'])) {
                    self::$downloadDir = (string) $cfg['download_dir'];
                }
                if (!empty($cfg['cookie_dir'])) {
                    self::$cookieDir = (string) $cfg['cookie_dir'];
                }
            }
        }
        foreach ([self::$storage, self::$downloadDir, self::$cookieDir] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
        }
    }

    /**
     * 生成稳定的设备标识（首次运行写入 storage/device.json）
     *
     * @return array{device_id:string,install_id:string}
     */
    public static function device(): array
    {
        $file = self::$storage . '/device.json';
        if (is_file($file)) {
            $raw = json_decode((string) file_get_contents($file), true);
            if (is_array($raw) && !empty($raw['device_id'])) {
                return ['device_id' => (string) $raw['device_id'], 'install_id' => (string) $raw['install_id']];
            }
        }
        $dev = self::randomId();
        $iid = self::randomId();
        self::boot();
        file_put_contents($file, json_encode(['device_id' => $dev, 'install_id' => $iid], JSON_PRETTY_PRINT));
        return ['device_id' => $dev, 'install_id' => $iid];
    }

    /** 19 位数字串，形似字节的 device_id */
    public static function randomId(): string
    {
        $s = (string) random_int(7, 9);
        for ($i = 0; $i < 18; $i++) {
            $s .= (string) random_int(0, 9);
        }
        return $s;
    }

    /** 客户端形态的公共查询参数 */
    public static function commonParams(): array
    {
        $d = self::device();
        return [
            'aid' => self::APP_ID,
            'app_name' => 'luna_pc',
            'region' => 'cn',
            'geo_region' => 'cn',
            'os_region' => 'cn',
            'sim_region' => '',
            'device_id' => $d['device_id'],
            'cdid' => '',
            'iid' => $d['install_id'],
            'version_name' => self::APP_VERSION,
            'version_code' => self::VERSION_CODE,
            'channel' => 'official',
            'build_mode' => 'official',
            'network_carrier' => '',
            'ac' => 'wifi',
            'tz_name' => 'Asia/Shanghai',
            'resolution' => '',
            'device_platform' => 'windows',
            'device_type' => 'Windows',
            'os_version' => 'Windows 11',
            'fp' => $d['device_id'],
        ];
    }

    public static function userAgent(): string
    {
        return 'LunaPC/' . self::APP_VERSION . '(' . self::BUILD_ID . ')';
    }
}
