# Qishui Music Toolkit (PHP)

[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)](https://www.php.net/)
[![Platform](https://img.shields.io/badge/platform-Windows-lightgrey.svg)]()

**简体中文** · [English](README.en.md)

A clean-room reimplementation of the Qishui Music (汽水音乐, ByteDance `luna` / `SodaMusic`)
PC client protocol: share-link parsing, source-audio resolution, CENC decryption, and
member quality unlock.

All business logic is PHP. The only non-PHP step is a local Node sidecar, because request
signing and `decodeSpade` (the audio key unwrap) live inside ByteDance's native modules
`bdms.node` / `device.node`. Nothing there can be reproduced in pure PHP.

**The repo ships no binaries and proxies nothing.** Every native library is read from the
client you installed yourself; downloaded files stay in your local `storage/`.

---

## What it does

| Feature | Status |
|---|---|
| Track search | ✅ |
| Track detail + LRC lyrics | ✅ |
| Source audio URL resolution | ✅ |
| **CENC (`cenc-aes-ctr`) decryption → playable m4a / FLAC** | ✅ |
| Member quality unlock (6 tiers incl. lossless FLAC) | ✅ |
| Preview-clip detection (warns instead of silently saving 60s) | ✅ |
| Cookie import from the local client | ✅ |
| Share-link / short-link parsing | ✅ |
| QR login | ⚠️ blocked by passport risk control, see below |
| Pure-GET JSON API for your own player | ✅ |

## Why a sidecar at all

Every API call to `api.qishui.com` needs two signatures the client computes natively:

1. **bdms** (`X-Helios` / `X-Medusa` style headers) — the anti-tamper signature
2. **bdticket** (`ticket_guard`) — device certificate headers, `bd-ticket-guard-*`

And the audio is CENC-encrypted; the per-track key comes out of `decodeSpade(spade_a)` in
`device.node`. Both are native N-API modules, so the PHP side shells out to a small Node
process (`signer/server.js`) that loads them from your installed client.

### CENC decryption

The decrypted file must keep identical length so all `stco` / `stsz` offsets stay valid:

```
stsd  → enca (encrypted audio sample entry) + sinf/schi/tenc (ivsize=8, kid)
senc  → 8-byte IV per sample
mdat  → AES-128-CTR ciphertext per sample

decrypt: nonce = IV(8B) + 8 zero bytes, AES-128-CTR per sample
rewrite: senc/saiz/saio/sinf → equal-length `free` boxes
         enca → original format (read from sinf/frma: mp4a for AAC, fLaC for FLAC)
```

## Requirements

Two things are **not** in the repo (both git-ignored) — you provide them:

| Directory | What | Size | Where from |
|---|---|---|---|
| `runtime/php/` | PHP 8.1+ NTS x64 with curl / openssl / sqlite3 / mbstring | ~88 MB | [windows.php.net](https://windows.php.net/download/) |
| `signer/electron/` | The Electron runtime bundled with Qishui Music (22.3.18-tt.1 / Node 16.17.1) | ~227 MB | Copy it from your own client install |

For `signer/electron/`: copy the runtime from your client directory, then **rename
`SodaMusic.exe` to `qsm-signer.exe`**. This is required — the client's launcher uses the
process name for its single-instance check, and a same-named sidecar makes the client exit
on startup.

## Quick start

```bat
start-signer.cmd                        :: signature sidecar, keep the window open
start-web.cmd                           :: web console on http://127.0.0.1:8899

:: log in once
::   http://127.0.0.1:8899/login.php

:: verify
runtime\php\php.exe bin\qsmusic info 7146240707408168993
```

## JSON API (pure GET)

Unified envelope: `{"code":0,"msg":"ok","data":...}`. Non-zero `code` on failure
(`4001` bad param, `5001` service, `5002` upstream, `5003` no source, `5004` prepare failed).

```
GET api.php?action=status
GET api.php?action=search&q=晴天&limit=20&offset=0     # with_url=1 adds per-track URLs
GET api.php?action=song&id=TRACK_ID                    # info + lyric + every tier + key
GET api.php?action=lyric&id=TRACK_ID
GET api.php?action=url&id=TRACK_ID&quality=lossless
GET api.php?action=stream&id=TRACK_ID&quality=lossless # decrypted, Range-capable
```

Each tier returns three things:

```json
{"quality":"lossless","codec":"flac","bitrate":1422694,"size":35318576,
 "url":"https://…douyinvod.com/…",          // raw, still CENC-encrypted
 "key":"<32-hex CENC key>","kid":"<32-hex>","iv_size":8,
 "play_url":"…/api.php?action=stream&id=…&quality=lossless"}
```

**`url` is encrypted** — feeding it straight to a player gives you noise. Use `play_url`
unless you want to do the AES-128-CTR pass yourself. `play_url` decrypts, caches under
`storage/cache/`, and serves byte ranges, so seeking works.

## Member vs anonymous

| Account | Tiers | Reality |
|---|---|---|
| Anonymous | 1 | `higher` only, and it is a **~56s preview clip**, not the full track |
| Member | 6 | `lossless` FLAC / `spatial` / `hi_res` / `highest` / `higher` / `medium`, full length |

Tier selection is entirely server-side by account entitlement. The `quality`, `vquality`,
`is_vip` and `privilege` query parameters were all tested and have **no effect** — swap the
cookie, not the request.

Anonymous responses are identifiable: `track_v2` exposes `track.duration` (full) against
`preview.duration` / `preview.start` (clip), plus `label_info.only_vip_playable`.

## Known limitations

- QR login returns `4031` from passport's `verify_center` risk control. Verified not to be a
  version, UA, `aid`, or TLS-fingerprint issue: real Chrome 157 driven over CDP gets the same
  block, and so does a request carrying full `bdticket` device-certificate headers. What is
  accepted appears to be the client's device certificate / `verifyFp`, which plain HTTP
  cannot produce. Use cookie import instead.
- Reading the client cookie store requires the **client to be closed** — Chromium holds the
  `Network/Cookies` lock, and VSS needs admin.
- Member-only tracks cannot be fetched at full length without a member cookie: VOD's
  `GetPlayInfo` ignores identity, but its SigV4 signature cannot be forged (only the AK is
  exposed, the SK is server-side).

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Bug reports and protocol updates are welcome —
include your client version and the failing request/response pair.

## License

[MIT](LICENSE)
