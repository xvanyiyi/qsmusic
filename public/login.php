<?php

declare(strict_types=1);

/**
 * 汽水音乐 —— 登录页（单文件）。
 *
 * 访问：http://127.0.0.1:8899/login.php
 *
 * 三条路把登录态写进 storage/cookies/current.json：
 *   1) 从本机汽水音乐客户端读取（要求客户端已退出）
 *   2) 粘贴 cookie 字符串（sessionid=...; sid_tt=...）
 *   3) 清空
 *
 * 写完立即用 /luna/pc/me 校验，并显示会员状态与音质档位。
 * api.php 会自动使用这份登录态。
 */

require __DIR__ . '/../src/bootstrap.php';

use Qsm\CookieJar;
use Qsm\Qishui\Importer;

set_time_limit(120);

$notice = null;   // ['type' => 'ok|err', 'text' => '...']
$detail = [];

/* ---------------- 处理提交 ---------------- */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $act = (string) ($_POST['act'] ?? '');
    try {
        $env = qsm_boot('current');
        if ($act === 'import') {
            @set_time_limit(180);
            $r = Importer::import();
            $r['jar']->save('', []);
            $env = qsm_boot('current');
            $notice = ['type' => 'ok', 'text' => sprintf(
                '已从客户端读取 %d 条 cookie（来源 %s）',
                (int) $r['count'],
                (string) $r['source']
            )];
        } elseif ($act === 'paste') {
            $raw = trim((string) ($_POST['cookie'] ?? ''));
            if ($raw === '') {
                throw new \RuntimeException('cookie 内容为空');
            }
            $env['jar']->loadString($raw);
            if (!$env['jar']->hasSession()) {
                throw new \RuntimeException('粘贴的内容里没有 sessionid，检查一下是不是少了');
            }
            $env['jar']->save('', []);
            $env = qsm_boot('current');
            $notice = ['type' => 'ok', 'text' => 'cookie 已保存'];
        } elseif ($act === 'clear') {
            $p = CookieJar::defaultFile('current');
            if (is_file($p)) {
                @unlink($p);
            }
            $env = qsm_boot('current');
            $notice = ['type' => 'ok', 'text' => '已清空登录态，当前为匿名'];
        }

        // 校验 + 抓会员信息
        if ($env['jar']->hasSession()) {
            try {
                $u = $env['login']->refreshUser();
                $detail = $u;
            } catch (\Throwable $e) {
                $detail = ['error' => $e->getMessage()];
            }
        }
    } catch (\Throwable $e) {
        $notice = ['type' => 'err', 'text' => $e->getMessage()];
    }
}

/* ---------------- 当前状态 ---------------- */
$env = qsm_boot('current');
$hasSession = $env['jar']->hasSession();
$uid = $env['jar']->uid();
$meta = [];
$cookieFile = CookieJar::defaultFile('current');
if (is_file($cookieFile)) {
    $meta = json_decode((string) @file_get_contents($cookieFile), true) ?: [];
}
if ($hasSession && $detail === []) {
    try {
        $detail = $env['login']->refreshUser();
    } catch (\Throwable $e) {
        $detail = ['error' => $e->getMessage()];
    }
}
$signerOk = false;
$signerMsg = '';
try {
    $h = $env['signer']->health();
    $signerOk = !empty($h['ok']);
    $signerMsg = $signerOk ? ('node ' . ($h['node'] ?? '?') . ' / ' . basename((string) ($h['native'] ?? ''))) : 'health 返回异常';
} catch (\Throwable $e) {
    $signerMsg = $e->getMessage();
}

$vip = !empty($detail['is_vip']);
$expire = '';
foreach (explode(';', $env['jar']->header()) as $seg) {
    $seg = trim($seg);
    if (str_starts_with($seg, 'sid_guard=')) {
        $parts = explode('|', rawurldecode(substr($seg, 10)));
        if (isset($parts[1]) && ctype_digit($parts[1])) {
            $expire = date('Y-m-d H:i', (int) $parts[1]);
        }
    }
}

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>汽水音乐 · 登录</title>
<style>
  :root{
    --bg:#0e1116; --card:#161b23; --line:#242c38; --text:#e6edf3; --muted:#8b98a9;
    --ok:#2ecc71; --err:#ff6b6b; --warn:#ffb020; --acc:#4c8dff;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--text);
    font:14px/1.6 ui-sans-serif,system-ui,"Segoe UI","Microsoft YaHei",sans-serif;
    display:flex;justify-content:center;padding:32px 16px}
  .wrap{width:100%;max-width:720px}
  h1{font-size:19px;margin:0 0 4px}
  .sub{color:var(--muted);font-size:12px;margin-bottom:20px}
  .card{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:18px;margin-bottom:14px}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .kv{display:grid;grid-template-columns:96px 1fr;gap:6px 12px;font-size:13px}
  .kv .k{color:var(--muted)}
  .badge{display:inline-block;padding:1px 8px;border-radius:20px;font-size:12px;border:1px solid}
  .b-ok{color:var(--ok);border-color:#1e5c3a;background:#10241a}
  .b-err{color:var(--err);border-color:#5c2020;background:#241212}
  .b-warn{color:var(--warn);border-color:#5c4a1e;background:#241f10}
  button{font:inherit;padding:8px 14px;border-radius:8px;border:1px solid var(--line);
    background:#1d2531;color:var(--text);cursor:pointer}
  button:hover{background:#25303f}
  button.primary{background:var(--acc);border-color:var(--acc);color:#fff}
  button.primary:hover{background:#3b7ae6}
  button.danger{color:var(--err);border-color:#5c2020}
  textarea{width:100%;min-height:92px;background:#0b1017;color:var(--text);
    border:1px solid var(--line);border-radius:8px;padding:10px;font-family:ui-monospace,Consolas,monospace;
    font-size:12px;resize:vertical}
  .notice{padding:10px 14px;border-radius:8px;margin-bottom:14px;font-size:13px}
  .n-ok{background:#10241a;border:1px solid #1e5c3a;color:#7ee2a8}
  .n-err{background:#241212;border:1px solid #5c2020;color:#ffa8a8}
  .hint{color:var(--muted);font-size:12px;margin:8px 0 0}
  code{background:#0b1017;padding:1px 5px;border-radius:4px;font-size:12px}
  a{color:var(--acc)}
  hr{border:0;border-top:1px solid var(--line);margin:16px 0}
</style>
</head>
<body>
<div class="wrap">
  <h1>汽水音乐 · 登录</h1>
  <div class="sub">登录态写入 <code>storage/cookies/current.json</code>，<a href="api.php?action=status">api.php</a> 自动使用它。</div>

  <?php if ($notice !== null): ?>
    <div class="notice <?= $notice['type'] === 'ok' ? 'n-ok' : 'n-err' ?>"><?= e($notice['text']) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="row" style="justify-content:space-between;margin-bottom:12px">
      <strong>当前状态</strong>
      <?php if ($hasSession): ?>
        <span class="badge <?= $vip ? 'b-ok' : 'b-warn' ?>"><?= $vip ? '会员 / ' . e((string) ($detail['vip_stage'] ?? 'vip')) : '已登录（非会员）' ?></span>
      <?php else: ?>
        <span class="badge b-err">匿名</span>
      <?php endif; ?>
    </div>
    <div class="kv">
      <span class="k">UID</span><span><?= $hasSession && $uid !== '' ? e($uid) : '<span style="color:var(--muted)">—</span>' ?></span>
      <span class="k">昵称</span><span><?= e((string) ($detail['nickname'] ?? '')) ?: '<span style="color:var(--muted)">—</span>' ?></span>
      <span class="k">会员</span><span><?= $vip ? '是' : '否' ?> <?= e((string) ($detail['vip_type'] ?? '')) ?></span>
      <span class="k">有效期</span><span><?= e($expire) ?: '<span style="color:var(--muted)">—</span>' ?></span>
      <span class="k">签名服务</span><span>
        <?php if ($signerOk): ?><span class="badge b-ok">正常</span>
        <?php else: ?><span class="badge b-err">未启动</span> <?php endif; ?>
        <span style="color:var(--muted);font-size:12px"><?= e($signerMsg) ?></span>
      </span>
    </div>
    <?php if (!empty($detail['error'])): ?>
      <div class="hint" style="color:var(--err)">校验失败：<?= e((string) $detail['error']) ?></div>
    <?php elseif (!$signerOk): ?>
      <div class="hint" style="color:var(--warn)">签名 sidecar 没起，先跑 <code>start-signer.cmd</code>，否则接口全部失败。</div>
    <?php elseif ($hasSession && !$vip): ?>
      <div class="hint" style="color:var(--warn)">这个账号不是会员。会员专享曲目只能拿到试听片段（约 60 秒）。</div>
    <?php endif; ?>
  </div>

  <div class="card">
    <strong>方式一 · 从本机客户端读取</strong>
    <p class="hint">先<b>完全退出</b>汽水音乐客户端（Chromium 对 cookie 库持独占锁，运行时读不到），点下面按钮，读完再把客户端打开即可。</p>
    <form method="post">
      <input type="hidden" name="act" value="import">
      <button class="primary" type="submit">读取客户端登录态</button>
    </form>
  </div>

  <div class="card">
    <strong>方式二 · 粘贴 cookie</strong>
    <form method="post">
      <input type="hidden" name="act" value="paste">
      <textarea name="cookie" placeholder="sessionid=xxxx; sessionid_ss=xxxx; sid_tt=xxxx; uid_tt=xxxx; ..."></textarea>
      <p class="hint">只要包含 <code>sessionid</code> 就能用。多余的键无影响。</p>
      <button class="primary" type="submit">保存</button>
    </form>
  </div>

  <div class="card">
    <div class="row" style="justify-content:space-between">
      <div>
        <strong>清空</strong>
        <div class="hint" style="margin:0">删掉本地登录态，回到匿名状态。</div>
      </div>
      <form method="post" onsubmit="return confirm('确定清空当前登录态？')">
        <input type="hidden" name="act" value="clear">
        <button class="danger" type="submit">清空登录态</button>
      </form>
    </div>
  </div>

  <div class="card">
    <strong>接口速览</strong>
    <div class="kv" style="margin-top:8px">
      <span class="k">搜索</span><span><code>api.php?action=search&q=晴天&limit=20</code></span>
      <span class="k">歌曲详情</span><span><code>api.php?action=song&id=曲目ID</code></span>
      <span class="k">仅歌词</span><span><code>api.php?action=lyric&id=曲目ID</code></span>
      <span class="k">仅直链</span><span><code>api.php?action=url&id=曲目ID&quality=lossless</code></span>
      <span class="k">可播放流</span><span><code>api.php?action=stream&id=曲目ID&quality=lossless</code></span>
    </div>
  </div>
</div>
</body>
</html>
