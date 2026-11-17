<?php

declare(strict_types=1);

/**
 * 汽水音乐 —— Web 控制台。
 *
 * 启动：php -S 127.0.0.1:8899 -t public
 */

require __DIR__ . '/../src/bootstrap.php';

use Qsm\Config;
use Qsm\CookieJar;
use Qsm\Qishui\Importer;

set_time_limit(0);
header('X-Content-Type-Options: nosniff');

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$raw = file_get_contents('php://input');
$in = [];
if ($raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
    $in = json_decode($raw, true) ?: [];
} else {
    $in = $_POST;
}

function jout(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function account(): string
{
    $a = (string) ($_GET['account'] ?? $_POST['account'] ?? 'current');
    return preg_replace('/[^A-Za-z0-9_\-]/', '', $a) ?: 'current';
}

if ($action !== '') {
    try {
        $env = qsm_boot(account());
        switch ($action) {
            case 'status': {
                $h = $env['signer']->health();
                $list = CookieJar::list();
                jout([
                    'ok' => true,
                    'signer' => $h,
                    'accounts' => $list,
                    'current' => basename($env['jar']->path() ?: 'current', '.json'),
                    'has_cookie' => !$env['jar']->isEmpty(),
                ]);
            }

            case 'search': {
                $kw = trim((string) ($in['q'] ?? ''));
                if ($kw === '') {
                    jout(['ok' => false, 'error' => '关键词为空'], 400);
                }
                $list = $env['api']->search($kw, 'track', '', (int) ($in['limit'] ?? 20));
                $out = [];
                foreach ($list as $t) {
                    $name = (string) ($t['name'] ?? '');
                    if ($name === '' && isset($t['name_parts'])) {
                        foreach ($t['name_parts'] as $p) {
                            $name .= is_array($p) ? (string) ($p['text'] ?? '') : (string) $p;
                        }
                    }
                    $artists = [];
                    foreach (($t['artists'] ?? []) as $a) {
                        if (!empty($a['name'])) {
                            $artists[] = (string) $a['name'];
                        }
                    }
                    $out[] = [
                        'id' => (string) ($t['id'] ?? ''),
                        'name' => $name,
                        'artists' => implode(' / ', $artists),
                        'album' => (string) ($t['album']['name'] ?? ''),
                        'duration' => (int) ($t['duration'] ?? 0),
                        'cover' => (string) (($t['album']['url_cover']['urls'][0] ?? '') ?: ''),
                        'vip' => !empty($t['is_vip']),
                    ];
                }
                jout(['ok' => true, 'tracks' => $out]);
            }

            case 'resolve': {
                $input = trim((string) ($in['input'] ?? ''));
                if ($input === '') {
                    jout(['ok' => false, 'error' => '输入为空'], 400);
                }
                $p = $env['share']->parse($input);
                $ids = $env['share']->resolveToTrackIds($input, (int) ($in['limit'] ?? 1));
                $a = $env['audio']->resolve($ids[0]);
                jout(['ok' => true, 'parsed' => $p, 'track_ids' => $ids, 'audio' => [
                    'sources' => array_map(static fn ($s) => [
                        'quality' => $s['quality'],
                        'vtype' => $s['vtype'],
                        'codec' => $s['codec'],
                        'bitrate' => $s['bitrate'],
                        'size' => $s['size'],
                        'encrypted' => $s['encrypted'],
                        'key_hex' => $s['key_hex'],
                        'main_url' => $s['main_url'],
                    ], $a['sources']),
                    'playable' => $a['playable'],
                ]]);
            }

            case 'download': {
                $input = trim((string) ($in['input'] ?? ''));
                $ids = $env['share']->resolveToTrackIds($input, (int) ($in['limit'] ?? 1));
                $results = [];
                foreach ($ids as $id) {
                    try {
                        $results[] = $env['downloader']->download($id, [
                            'quality' => (string) ($in['quality'] ?? ''),
                            'dir' => (string) ($in['dir'] ?? Config::$downloadDir),
                        ]);
                    } catch (\Throwable $e) {
                        $results[] = ['track_id' => $id, 'error' => $e->getMessage()];
                    }
                }
                jout(['ok' => true, 'results' => $results, 'dir' => Config::$downloadDir]);
            }

            case 'login_qr': {
                $q = $env['login']->begin();
                jout(['ok' => true, 'token' => $q['token'], 'qr' => $q['qr_content']]);
            }

            case 'login_poll': {
                $token = (string) ($in['token'] ?? $_GET['token'] ?? '');
                if ($token === '') {
                    jout(['ok' => false, 'error' => '缺少 token'], 400);
                }
                $st = $env['login']->poll($token);
                jout(['ok' => true, 'status' => $st['status'], 'confirmed' => $st['confirmed'],
                    'error_code' => $st['error_code'], 'user' => $st['user'], 'raw' => $st['raw']]);
            }

            case 'import': {
                $r = Importer::import($in['userdata'] ?? null);
                $jar = $r['jar'];
                $jar->save(CookieJar::defaultFile(account()));
                $env2 = qsm_boot(account());
                $user = null;
                $err = null;
                try {
                    $user = $env2['login']->refreshUser();
                } catch (\Throwable $e) {
                    $err = $e->getMessage();
                }
                jout(['ok' => true, 'source' => $r['source'], 'count' => $r['count'],
                    'user' => $user, 'verify_error' => $err, 'file' => $jar->path()]);
            }

            case 'whoami': {
                if ($env['jar']->isEmpty()) {
                    jout(['ok' => false, 'error' => '没有 cookie'], 400);
                }
                jout(['ok' => true, 'user' => $env['login']->refreshUser()]);
            }

            case 'save_cookie': {
                $ck = trim((string) ($in['cookie'] ?? ''));
                if ($ck === '') {
                    jout(['ok' => false, 'error' => 'cookie 为空'], 400);
                }
                $env['jar']->loadString($ck);
                if (!$env['jar']->hasSession()) {
                    jout(['ok' => false, 'error' => 'cookie 里没有 sessionid'], 400);
                }
                $env['jar']->save(CookieJar::defaultFile(account()));
                $user = null;
                try {
                    $user = $env['login']->refreshUser();
                } catch (\Throwable $e) {
                }
                jout(['ok' => true, 'user' => $user, 'file' => $env['jar']->path()]);
            }

            default:
                jout(['ok' => false, 'error' => '未知 action'], 404);
        }
    } catch (\Throwable $e) {
        jout(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

require __DIR__ . '/ui.html';
