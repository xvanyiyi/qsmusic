<?php

declare(strict_types=1);

use Qsm\Config;
use Qsm\CookieJar;
use Qsm\Http\Client;
use Qsm\Qishui\Api;
use Qsm\Qishui\Audio;
use Qsm\Qishui\Downloader;
use Qsm\Qishui\Login;
use Qsm\Qishui\ShareParser;
use Qsm\Signer;

require __DIR__ . '/autoload.php';

Config::boot();

/**
 * 组装整套服务。
 *
 * @param string $cookieName 使用哪个账号的 cookie（storage/cookies/<name>.json）
 */
function qsm_boot(string $cookieName = 'current'): array
{
    $jar = new CookieJar(CookieJar::defaultFile($cookieName));
    $signer = new Signer(Config::$signerUrl);
    $api = new Api($signer, $jar);
    $http = new Client();
    $audio = new Audio($api);
    return [
        'jar' => $jar,
        'signer' => $signer,
        'api' => $api,
        'http' => $http,
        'audio' => $audio,
        'downloader' => new Downloader($api, $audio, $http),
        'share' => new ShareParser($api, $http),
        'login' => new Login($signer, $jar, $api),
    ];
}
