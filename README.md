# 汽水音乐解析 / 下载 / 登录工具（PHP）

逆向 `汽水音乐 PC 3.5.1`（Electron 客户端，内部代号 `luna` / `SodaMusic`）得到的完整实现：
解析分享链接、拿源音频直链、解 CENC 加密拿到可播放的 m4a / FLAC、cookie 导入后解锁会员音质。

业务逻辑 100% PHP；只有一个不可替代的原生环节交给本地 Node sidecar —— 请求签名与
`decodeSpade`（音频解密 key），因为那是字节的原生库 `bdms.node` / `device.node` 里做的，
纯 PHP 无法复刻。

**仓库不含任何二进制、不代理任何内容。** 所有原生库都从你自己安装的客户端里读取，
下载到的文件只存在于你本机 `storage/`。

| | |
|---|---|
| 语言 | PHP 8.1+ / Node 16（sidecar） |
| 依赖 | 无 composer 包、无 npm 包 |
| 许可 | [MIT](LICENSE) |

---

## 0. 快速试一下

```bat
:: 1) 起签名服务（需要先准备 3.1 里那两样东西）
start-signer.cmd

:: 2) 另开一个窗口，起 Web
start-web.cmd

:: 3) 浏览器登录一次
::    http://127.0.0.1:8899/login.php

:: 4) 命令行验证
runtime\php\php.exe bin\qsmusic info 7146240707408168993
```

给播放器对接的 JSON 接口（纯 GET）：

```
http://127.0.0.1:8899/api.php?action=search&q=晴天&limit=20
http://127.0.0.1:8899/api.php?action=song&id=7146240707408168993
http://127.0.0.1:8899/api.php?action=stream&id=7146240707408168993&quality=lossless
```

详见 [3.5 节](#35-对接自己的播放器只要-loginphp--apiphp)。

---

## 1. 它能做什么

| 能力 | 状态 | 说明 |
|---|---|---|
| 搜索曲目 | ✅ | `/luna/pc/search/track`，带完整签名 |
| 曲目详情 + 歌词 | ✅ | `/luna/pc/track_v2`，LRC 带时间轴 |
| 源音频直链 | ✅ | `/luna/pc/media-player` → VOD 直链（`*.douyinvod.com`） |
| 下载音频 | ✅ | 支持备用域名、分块下载 |
| **解密播放** | ✅ | `cenc-aes-ctr` → 普通 m4a / flac-in-mp4，见第 4 节 |
| 歌词 / 元数据落盘 | ✅ | 同名 `.lrc` + `.json` |
| 会员音质解锁 | ✅ | 会员 cookie → 6 档（含 `lossless` FLAC 805kbps） |
| **试听片段识别** | ✅ | 自动比对完整时长，试听会明确告警，不再下出「1 分钟」还以为是成功 |
| 扫码登录 | ⚠️ 协议已实现，被 passport 风控拦 | 见第 6 节 |
| 客户端 cookie 导入 | ✅（客户端需退出） | 见第 7 节，实测一次拿全 18 条登录态 |
| 手动粘贴 cookie | ✅ | 推荐，见第 7 节 |
| 歌单 / 专辑批量下载 | ✅ | `--limit=N` |
| 分享链接解析 | ✅ | 短链跟随 + ID 提取 + 关键词回落 |

### 音质档位（同一首歌实测）

| 账号 | 档位 | 实测 |
|---|---|---|
| 匿名 | 1 档 | 只有 `higher`，且是 **56 秒试听片段**（916KB） |
| 会员 | 6 档 | `lossless` FLAC 805kbps 19.4MB / `spatial` / `hi_res` / `highest` / `higher` / `medium`，全部为完整 3:22 |

档位由服务端按账号权益下发，`media-player` 的 `quality` / `vquality` / `is_vip` 等参数**实测全部无效**
（24 组组合），所以不要去改请求参数，换 cookie 才有用。

---

## 1.1 「只有 1 分钟」是怎么回事

匿名（或会员 cookie 失效）时，`media-player` 下发的是**独立的试听视频**，不是整首截断：

- `track_v2` 里能看到真相：`track.duration = 202052`（完整），但 `preview.duration = 56695`、
  `preview.start = 145344`（从完整音轨的 145.3 秒处切了 56.7 秒）、`preview.vid` 是另一个 video_id。
- `label_info` 会标 `only_vip_playable: true` / `only_vip_download: true`，以及
  `quality_only_vip_can_download: ["medium","higher","highest","lossless"]`。

工具现在会自动判定并在 `info` / `download` 里告警：

```
注意: 拿到的是试听片段（57 秒 / 完整 202 秒），解锁完整音源需要登录会员账号
```

想拿整首，只有换会员 cookie 一条路（第 7 节）。

---

## 2. 目录结构

```
qsmusc/
├─ bin/qsmusic              CLI 入口
├─ public/
│   ├─ index.php            Web API + 路由
│   ├─ ui.html              控制台界面
│   └─ assets/qrcode.min.js 二维码渲染（已本地化，不依赖外网）
├─ src/
│   ├─ autoload.php
│   ├─ bootstrap.php        对象装配
│   ├─ Config.php           设备指纹 / 版本号 / 目录
│   ├─ Signer.php           签名 sidecar 客户端（含 decodeSpade）
│   ├─ CookieJar.php        cookie 存取
│   ├─ Http/Client.php      curl 封装（请求 + 下载）
│   └─ Qishui/
│       ├─ Api.php          luna pc 接口全集
│       ├─ Audio.php        音源解析 + 音质排序 + key 解析
│       ├─ Cenc.php         CENC 解密器（纯 PHP）
│       ├─ Downloader.php   下载 + 解密 + 落盘
│       ├─ Login.php        扫码登录
│       ├─ Importer.php     从本机客户端提取 cookie
│       └─ ShareParser.php  分享链接 → 曲目 ID
├─ signer/server.js         签名 sidecar（Node，跑在 Electron 里）
├─ config/local.php.example 可选覆盖配置
└─ storage/
    ├─ device.json          设备指纹
    ├─ cookies/*.json       账号 cookie
    └─ downloads/           下载产物
```

---

## 3. 环境与启动

### 3.1 你需要自己准备两样东西

仓库里**不含**任何二进制，这两样要自己放进来（都已被 `.gitignore` 排除）：

| 目录 | 内容 | 体积 | 从哪来 |
|---|---|---|---|
| `runtime/php/` | PHP 8.3+ (NTS x64)，需启用 curl / openssl / sqlite3 / mbstring | ~88 MB | 官方 [windows.php.net](https://windows.php.net/download/)，解压到 `runtime/php/` |
| `signer/electron/` | 汽水音乐自带的 Electron 运行时（22.3.18-tt.1 / Node 16.17.1），用来加载官方 `bdms.node` | ~227 MB | 从你自己装的汽水音乐客户端目录整体复制 |

`signer/electron/` 怎么来：找到汽水音乐安装目录（如 `<安装盘>:\qishui\Soda Music\3.5.1\`），
把里面的 Electron 运行时（`SodaMusic.exe`、`*.dll`、`resources\` 等）整个复制到 `signer/electron/`，
然后**把 `SodaMusic.exe` 改名为 `qsm-signer.exe`**。

> **改名这步不能省**：客户端启动器按进程名做单实例判断，sidecar 与它同名会让客户端起来就退出。

```bat
:: 一键起服务 + 打开控制台
start-all.cmd
```

或分开起：

```bat
start-signer.cmd    :: 签名服务，窗口别关
start-web.cmd       :: Web 控制台 http://127.0.0.1:8899
```

命令行调用：

```bat
runtime\php\php.exe bin\qsmusic health
```

> 前提：本机装过汽水音乐 PC 客户端 —— 签名要调它 `resources\app.asar.unpacked\bdms.node`
> 和 `device.node`。脚本会自动扫描 `C:` `D:` `E:` 找 `Soda Music` 目录，找不到就用
> `QSM_NATIVE_DIR` 手工指定。

### 3.2 手动启动 / 自定义

```bat
:: 指定原生库目录和端口
set QSM_NATIVE_DIR=D:\qishui\Soda Music\3.5.1\resources\app.asar.unpacked
set QSM_SIGNER_PORT=8791

set ELECTRON_RUN_AS_NODE=1
signer\electron\qsm-signer.exe signer\server.js
```

成功会看到：

```json
{"ok":true,"deviceId":"...","version":"3.5.1","native":"...\\bdms.node","node":"16.17.1","electron":"22.3.18-tt.1..."}
```

> 注意：客户端安装目录里那个 2.4MB 的 `SodaMusic.exe` 是启动器，Electron 被编进了
> `main.dll`，不认 `ELECTRON_RUN_AS_NODE`。必须用 `signer/electron/` 里那份独立运行时。

### 3.3 命令行

```bat
php bin/qsmusic health
php bin/qsmusic search "晴天" --limit=5
php bin/qsmusic info 7146240707408168993
php bin/qsmusic url 7146240707408168993
php bin/qsmusic download 7146240707408168993 --dir=D:\Music
php bin/qsmusic download "https://qishui.douyin.com/s/xxxxx/" --limit=10
php bin/qsmusic decrypt enc.m4a <32位16进制CENC密钥> out.m4a
```

实测输出：

```
> php bin/qsmusic search "晴天" --limit=5
 1. 晴天                                   晚风纯音           3:57  7676337516358617115
 2. 晴天                                   J.sheoy                3:19  7485660908549720080
 3. 晴天（雨渐渐）                    黄靖轩              4:13  7676337516358518811
 4. 晴天 (鋼琴版) [原唱: 周杰倫]   Bryan Chi              3:58  7157130433749059585
 5. 晴天                                   晴天                 1:55  7402972976926672911

> php bin/qsmusic info 7146240707408168993
曲目: 因为爱情
专辑: 中国新歌声第二季第一期
时长: 3:22
音源: 1 档
  - higher     m4a    aac      132kbps  916.6KB enc=yes key=<32位16进制CENC密钥>

> php bin/qsmusic download 7146240707408168993
[1/1] 7146240707408168993
  → storage/downloads/周杰伦 _ 那英 - 因为爱情.m4a  (916.6KB, higher)
  → storage/downloads/周杰伦 _ 那英 - 因为爱情.lrc
```

下完的文件读 box 结构是干净的 `ftyp / moov / free / mdat`，没有 `enca` `senc` `tenc`，
任何播放器直接能放。

### 3.4 Web 控制台

```bat
start-web.cmd
:: 或
php -S 127.0.0.1:8899 -t public
```

浏览器打开 `http://127.0.0.1:8899`：搜索 / 解析直链 / 直接下载 / 扫码登录 / 导入 cookie。
二维码在前端用本地 `assets/qrcode.min.js` 渲染，不依赖外网。

### 3.5 对接自己的播放器：只要 `login.php` + `api.php`

不想用控制台、只想给自己软件调接口的话，用这两个文件（都在 `public/` 下，同一个 PHP 内置服务器）：

| 文件 | 作用 |
|---|---|
| `login.php` | 登录界面：读客户端登录态 / 粘贴 cookie / 清空，实时显示会员状态与签名服务健康度 |
| `api.php` | **纯 GET、只回 JSON**。搜索、歌曲信息、歌词、直链、解密后可直接播放的流 |

打开 `http://127.0.0.1:8899/login.php` 登录一次，之后 `api.php` 自动带登录态。

#### 接口一览

统一返回 `{"code":0,"msg":"ok","data":...}`，失败时 `code` 非 0（`4001` 参数、`5001` 服务、`5002` 上游、`5003` 无音源、`5004` 准备失败）。

```
GET api.php?action=status
GET api.php?action=search&q=晴天&limit=20&offset=0        # 加 with_url=1 会顺带返回每首的直链（慢）
GET api.php?action=song&id=曲目ID                          # 歌曲信息 + 歌词 + 各档直链 + key
GET api.php?action=lyric&id=曲目ID                         # 只返回 LRC
GET api.php?action=url&id=曲目ID&quality=lossless          # 只返回下载链接
GET api.php?action=stream&id=曲目ID&quality=lossless       # 直接返回可播放文件（支持 Range）
```

`search` 返回示例：

```json
{"code":0,"msg":"ok","data":{"keyword":"晴天","total":3,"tracks":[
  {"id":"7485660908549720080","name":"晴天","artists":"J.sheoy","album":"万事休",
   "duration":198,"duration_ms":198600,"duration_text":"03:18",
   "cover":"https://p3-luna.douyinpic.com/img/tos-cn-v-2774c002/oQMpqAoBzAax...",
   "vip":false,
   "song_url":"http://127.0.0.1:8899/api.php?action=song&id=7485660908549720080"}
]}}
```

`song` 里每个档位给三样东西，按你的播放器能力挑：

```json
{"quality":"lossless","codec":"flac","container":"mp4","bitrate":1422694,"size":35318576,
 "url":"https://v5-se-ex-mc-luna.douyinvod.com/…",        // 官方原始直链（CENC 加密，自己解用这个）
 "backup_url":"https://v26-luna.douyinvod.com/…",
 "encrypted":true,
 "key":"<32位16进制CENC密钥>",                 // 16 字节 CENC key
 "kid":"<32位16进制KID>","iv_size":8,
 "play_url":"http://127.0.0.1:8899/api.php?action=stream&id=7485660908549720080&quality=lossless"}
```

**要点：`url` 是加密的**（`cenc-aes-ctr`，`iv_size=8`）。播放器直接播会全是噪音。
想省事就直接用 **`play_url`** —— PHP 侧会下载、解密、缓存到 `storage/cache/`，
然后按 Range 输出，任何播放器都能直接播、能拖进度。想自己解就下 `url` + 用 `key`。

实测（`higher` 档 3.2MB）：首次 10.2 秒解密完成，第二次命中缓存 1.8 秒；
`Range: bytes=1000000-1000099` → `206`，返回字节与原文件逐字节一致；
`Range: bytes=-1000` 也能正确取尾段。

未登录时接口照常工作，但会员曲目只给 56～60 秒试听，`is_preview` 会是 `true`，
`preview_note` 写明「试听片段（60 秒 / 完整 199 秒）」。`status` 里的 `logged_in` / `is_vip` 可用来判断。

---

## 4. 音频为什么能听到 —— CENC 解密原理

`media-player` 返回的 m4a 是 **CENC 加密**的，直接下载只有噪音。判定依据（读 mp4 box 就能看到）：

```
moov/trak/mdia/minf/stbl/stsd   → enca（加密音频样本描述）
                     .../sinf/schi/tenc → version 0, isProtected, ivsize=8, kid=<16B>
                     .../senc   → 每个样本 8 字节 IV
                     .../saiz /saio → 辅助信息
mdat                            → 每样本 AES-128-CTR 密文
```

需要三样东西：

1. **key**：`media-player` → `video_model.video_list[].encrypt_info.spade_a`（或 VOD 明细里的同名
   字段）。这是 37 字节的 base64，官方 `device.node` 的 `decodeSpade(spade_a)` 直接吐出
   16 字节 key（32 位 hex）。sidecar 的 `POST /spade` 就是这个调用。
2. **IV**：`senc` 里逐样本的 8 字节，拼 8 字节零构成 AES-CTR 的 16 字节 counter。
3. **样本表**：`stsz`（尺寸）+ `stsc`（每 chunk 样本数）+ `stco/co64`（chunk 偏移）算出每个
   样本的文件偏移。

`src/Qishui/Cenc.php` 做的事：

- 逐样本 `aes-128-ctr` 解密 `mdat`
- 把 `senc` / `saiz` / `saio` / `sinf` **等长改写成 `free`**，`enca` 改 `mp4a`
- 关键点：**不改文件长度**，所有 box 偏移保持有效，`stco` 无需重算

解完就是一个普通 m4a，任何播放器都能放。同一首的 key 只跟 `spade_a` 有关，换曲要重新解。

---

## 5. 请求签名（为什么必须有 sidecar）

`/luna/pc/*` 的每个请求都要两个头：

```
X-Helios: ...
X-Medusa: ...
```

由 `bdms.node` 的 `generateHttpSignatureHeaders(url, headerLines)` 计算，输入是
**最终完整 URL（查询参数全部拼好）+ 实际发送的头列表**，输出 CRLF 分隔的头。改动其中任何
一个字节都会导致签名不匹配（服务端返回 `1000062 应用版本有风险`）。

`signer/server.js` 把这件事包成 HTTP：

- `GET  /health` → 原生库是否加载成功
- `POST /fetch` → `{method,url,headers,body}` 签名并转发，回传 status/headers/body/setCookie
- `POST /spade` → `{spade_a}` → `{key, keyHex}`
- `POST /sign` → 只算签名不发送

签名规则（从 3.5.1 的 `request.ts` 反推）：`qishui.com` 的所有请求都签，
但路径含 `/passport/` 或 `/ttwid/` 的除外；`bdticket.node` 另有一套，当前流程不需要。

---

## 6. 扫码登录：协议已通，卡在风控

实现（`src/Qishui/Login.php`）：

```
GET /passport/web/get_qrcode/?next=https://luna-pc.bytedance.net/login
  → data.token, data.qrcode_index_url（二维码内容直接用它）
用户扫码 → GET /passport/web/check_qrconnect/?next=...&token=...
  → data.status: new → scanned → confirmed / expired
  → confirmed 时 Set-Cookie 下发 sessionid，落盘后调 /luna/pc/me 记录会员状态
```

实测 `get_qrcode` 恒返回：

```json
{"data":{"error_code":4031,"description":"当前版本过低，需要 2.8.8 及以上版本…"}}
```

### 已经排掉的所有假设

| 试过的 | 结果 |
|---|---|
| `version_code` = 3050100 / 30501000 / 3051000 / 3050101 / 3050120 | 全部 4031 |
| `device_platform` = windows / mac / 缺省 | 全部 4031 |
| UA 6 种（`LunaPC/3.5.1(3050100)`、Chrome、Electron 混合等） | 全部 4031 |
| `device_register` 成功注册 device_id / install_id 后再请求 | 仍 4031 |
| `aid=386089` / `aid=1128` | `error_code 16 无权限` / `11 此功能不支持当前app`，说明 386088 是对的 |
| 补 `is_new_login=1&need_logo=false&need_short_url=false`（照抄官方 account-sdk） | 仍 4031 |
| 接上 `bdticket.node` ticket_guard，请求带齐 `bd-ticket-guard-version/-ree-public-key/-server-cert-sn` | **仍 4031**（但 `get_client_cert` 能成功返回 server_cert） |

### 关键发现：passport 的正确 aid 是 6383

官方 `@byted/douyin-login-new` 的 `api/index.js` 里写着
「请求参数统一使用 6383」，而不是业务接口的 386088。换上之后错误文案**变了**：

```
aid=6383  → 您正在尝试访问的网站存在安全风险，为保护您的抖音账号安全与隐私，已阻止此次访问
aid=386088 → 当前版本过低，需要 2.8.8 及以上版本，Mac 商店暂未上架最新版…
```

说明 `aid=6383` 才是登录该走的分支，卡点在 passport 的 `verify_center` 风控。

### 真实 Chromium 也过不去，所以不是 TLS 指纹问题

用 CDP 驱动本机 Chrome 157（真实内核、非 headless UA、先访问 `www.qishui.com` 预热 cookie），
直接导航到 `get_qrcode` 接口，返回的仍是同一句「您正在尝试访问的网站存在安全风险」。
也就是说：**风控认的是客户端序列 / verifyFp / 设备证书，不是浏览器指纹**，
纯 HTTP 复刻（哪怕用真浏览器）都不够。

`ttnet.node`（Cronet 网络栈，`Hello from ttnet with Cronet!`）是客户端真正出网的通道，
但 `initTTNet(...)` 一调用就把 node 事件循环锁死（`setTimeout` 永不触发），
在 `ELECTRON_RUN_AS_NODE` 下无法驱动，没有实用价值。

**结论：扫码登录这条协议路，在当前服务端策略下走不通。**
要拿登录态请用下面第 7 节 —— 两条路都实测可用，其中 7.2 一次就能拿到 18 条登录态。

---

## 6.1 能不能「免 ck」拿会员音源？逐条试过的结论

把匿名与会员两份 `media-player` 响应做字段级对比，结论如下。

### VOD 层不校验身份 —— 这是唯一可利用的漏洞面

拿**会员的** `url_player_info` 用**匿名身份**去请求，返回完整数据：

```
Duration: 202.098  Total: 5
  spatial 324246  8189655
  hi_res  321428  8118227
  medium   68207 1723083
  higher  132188 3339370
  highest 260182 6572787
```

即 VOD 的 `GetPlayInfo` 只认 URL 里的 AWS SigV4 签名，不看 cookie。

### 但签名拿不到，所以仍然不通

| 尝试 | 结果 |
|---|---|
| `track_v2` 匿名 → `track.vid` | ✅ 能拿到**完整版 video_id**（`v03ad6g1...`），`bit_rates` 也给了完整文件大小 |
| 用完整 vid 当 `media_id` 查 `media-player` | ❌ `no player_infos`（media_id 只认 track_id） |
| 改试听签名 URL 里的 VideoID | ❌ SigV4 覆盖 query，改一个字符就失效 |
| `media_type` = track/audio/video/mv/radio/song | ❌ 仍 56.7 秒试听 |
| `queue_type` = 1/2/radio/recommend/collection/playlist | ❌ 仍 56.7 秒 |
| `&is_vip=1` / `&privilege=1` / `&quality=lossless` / `&force=1` | ❌ 全无效（档位服务端按权益下发） |
| 伪造头 `x-luna-is-local-user: 1` | ❌ 无效（要 cookie 校验） |
| 匿名 `url_player_info` | ❌ 只给 `Duration: 56.703 / Total: 1` |

卡点是 VOD 的临时凭证：`X-Amz-Credential=AKLT…（已脱敏）...`
只有 AK，SK 在服务端；没有 SK 就无法为「完整版 VideoID」自签一个 `GetPlayInfo`。

**所以：会员专享曲目（`only_vip_playable: true`）免 ck 拿整首，纯协议层做不到。**
工具的正确做法是换会员 cookie —— 一旦带上，档位立刻从 1 档变 6 档，无需改任何参数。

> 反过来：**非 VIP 曲目本来就不需要 ck**，匿名也能拿完整音源。工具对这类曲目直接可用。

---

## 7. 拿会员 cookie 的两条实用路径

### 7.1 手动粘贴（最快）

从浏览器或任意已登录环境复制 `sessionid` 那一串，粘贴进来即可：

```bat
php bin/qsmusic login-import            :: 走 7.2 自动提取
```

或在 Web 控制台「账号 → 粘贴客户端 cookie」里贴：

```
sessionid=xxxx; sessionid_ss=xxxx; sid_tt=xxxx; uid_tt=xxxx; ...
```

粘进来后会自动调 `/luna/pc/me` 校验，记录 `uid / nickname / is_vip / vip_stage`，
并立刻反映到音质档位上（实测同一首歌从 1 档变 6 档）。

保存位置：`storage/cookies/current.json`，之后所有命令自动带上。

### 7.2 从本机客户端提取（客户端必须先退出）

`src/Qishui/Importer.php` 的实现：

1. 读 `%APPDATA%\SodaMusic\Local State` → `os_crypt.encrypted_key`
2. base64 解码，去掉 `DPAPI` 前缀，用 `ProtectedData.Unprotect(..., CurrentUser)` 解出 32 字节主密钥
3. 复制 `Network/Cookies`（SQLite）
4. 逐行解 `encrypted_value`：`v10/v11` = AES-256-GCM（12 字节 nonce + 密文 + 16 字节 tag）
5. 挑出 `sessionid` / `sessionid_ss`，落盘

```bat
taskkill /IM SodaMusic.exe /F
php bin/qsmusic login-import
```

实测输出（一次拿全）：

```
来源: C:\Users\<你>\AppData\Roaming\SodaMusic
cookie 条数: 18
已保存: storage/cookies/current.json
```

拿到的 18 条里包含 `sessionid` / `sessionid_ss` / `sid_tt` / `sid_guard` / `uid_tt` /
`uid_tt_ss` / `ttwid` / `msToken` / `d_ticket` / `passport_assist_user` / `sid_ucp_v1` /
`ssid_ucp_v1` 等，足够过 `/luna/pc/me` 并直接解锁 6 档音质。

> 客户端运行时 Chromium 对 `Network/Cookies` 持独占锁，任何方式（共享读、SQLite
> `immutable=1`、VSS 除外）都打不开，必须先退出客户端。VSS 需要管理员，脚本没做。
> 导入完成后随时可以把客户端重新打开，登录态不受影响。

---

## 8. 关键参数速查

| 项 | 值 |
|---|---|
| API | `https://api.qishui.com` |
| aid | `386088` |
| app_name | `luna_pc` |
| version | `3.5.1` / code `3050100` |
| UA | `LunaPC/3.5.1(3050100)` |
| device_platform | `windows` |
| 搜索 | `GET /luna/pc/search/{track\|all}?q=&cursor=&search_method=keyword` |
| 曲目 | `GET /luna/pc/track_v2?track_id=` |
| 播放 | `GET /luna/pc/media-player?media_id=&media_type=track&enable_dash=1&enable_refresh_api=1` |
| 登录 | `GET /passport/web/get_qrcode/` · `POST /passport/web/check_qrconnect/`（**aid=6383**，passport 不用 386088） |
| 用户 | `GET /luna/pc/me` → `my_info.{id,nickname,is_vip,vip_stage}` |
| 二维码落地页 | `https://bff-pc.qishui.com/light/invoke/scan_login?token=&os=Windows&computer_name=` |

音质档位（`video_meta.quality`）：`standard` < `higher` < `excellent` < `hires` < `flac` < `lossless`。
匿名实测只给 `higher`。

---

## 9. 已知边界

- 匿名只能下 `higher`，而且那是 **56 秒试听片段**；整首要会员 cookie（带上即 6 档，见第 1 节表）。
- `media-player` 的 `quality` / `vquality` / `is_vip` / `privilege` 参数对返回档位**无效**
  （试过 24+ 种组合），档位由服务端按账号权益决定，换 cookie 才有用。
- 扫码登录受 passport `verify_center` 风控限制，见第 6 节；免 ck 的可行性边界见 6.1。
- `Login::begin()` 在 4031 时会抛出带 `error_code` 的异常，UI 会提示改用 cookie 方式。
- 读客户端 cookie 库要求 **客户端已退出**（`Network/Cookies` 被 Chromium 独占锁，
  非管理员无法 VSS 快照）。导入完可随时重新打开客户端。
- sidecar 的可执行文件已改名为 `qsm-signer.exe`：客户端启动器按进程名做单实例判断，
  与 sidecar 同名会导致客户端起来就退出。
- 下载文件名格式 `歌手 - 歌名.m4a`（`lossless` 档是 flac-in-mp4，扩展名 `.mp4`），
  非法字符替换为 `_`，长度截断 120 字。
