#!/usr/bin/env node
/**
 * 汽水音乐签名 sidecar
 * ------------------------------------------------------------------
 * 用官方 bdms.node (X-Helios / X-Medusa 签名) + bdticket.node 复现
 * LunaPC 客户端的请求签名，供 PHP 主控调用。
 *
 * 运行方式（Windows，借用已安装客户端的 Electron 16 二进制）：
 *   set ELECTRON_RUN_AS_NODE=1
 *   "D:\qishui\Soda Music\3.5.1\SodaMusic.exe" signer\server.js
 * 或使用 portable Electron（见 start.ps1）
 *
 * 接口：
 *   GET  /health                       -> {ok, deviceId, version}
 *   POST /sign    {url, headers}       -> {headers:{X-Helios, X-Medusa}}
 *   POST /fetch   {method,url,headers,body,binary}
 *                                      -> {status, headers, body, base64?}
 */
'use strict'

const http = require('http')
const https = require('https')
const fs = require('fs')
const path = require('path')
const { URL } = require('url')

const PORT = Number(process.env.QSM_SIGNER_PORT || 8791)
const HOST = process.env.QSM_SIGNER_HOST || '127.0.0.1'

const APP_VERSION = process.env.QSM_APP_VERSION || '3.5.1'
const VERSION_CODE = process.env.QSM_VERSION_CODE || '3050100'
const APP_ID = process.env.QSM_APP_ID || '386088'
const BUILD_ID = process.env.QSM_BUILD_ID || VERSION_CODE
const DEVICE_ID = process.env.QSM_DEVICE_ID || '7000000000000000001'
const INSTALL_ID = process.env.QSM_INSTALL_ID || '8000000000000000002'
const CHANNEL = process.env.QSM_CHANNEL || 'official'
const OS_VERSION = process.env.QSM_OS_VERSION || 'Windows 11'

/* ---------- 定位 bdms.node / bdticket.node ---------- */

function candidateDirs() {
  const dirs = []
  if (process.env.QSM_NATIVE_DIR) dirs.push(process.env.QSM_NATIVE_DIR)
  dirs.push(path.join(__dirname, 'native'))
  // 常见安装位置
  const roots = [
    'D:\\qishui\\Soda Music\\3.5.1\\resources\\app.asar.unpacked',
    'C:\\Program Files\\Soda Music\\resources\\app.asar.unpacked',
    'C:\\Program Files (x86)\\Soda Music\\resources\\app.asar.unpacked',
    path.join(process.env.LOCALAPPDATA || '', 'Programs', 'Soda Music', 'resources', 'app.asar.unpacked'),
  ]
  for (const r of roots) dirs.push(r)
  // 自动扫描 D:/C: 下的 Soda Music
  for (const drive of ['C:', 'D:', 'E:']) {
    try {
      for (const top of fs.readdirSync(drive + '\\')) {
        const p = path.join(drive + '\\', top)
        let st
        try { st = fs.statSync(p) } catch { continue }
        if (!st.isDirectory()) continue
        if (!/soda|qishui|汽水/i.test(top)) continue
        dirs.push(path.join(p, 'resources', 'app.asar.unpacked'))
        try {
          for (const ver of fs.readdirSync(p)) {
            dirs.push(path.join(p, ver, 'resources', 'app.asar.unpacked'))
          }
        } catch {}
      }
    } catch {}
  }
  return [...new Set(dirs)]
}

function loadNative() {
  const tried = []
  for (const dir of candidateDirs()) {
    const f = path.join(dir, 'bdms.node')
    if (!fs.existsSync(f)) continue
    tried.push(f)
    try {
      process.chdir(dir) // metasecml.dll 同目录
      const mod = require(f)
      if (typeof mod.generateHttpSignatureHeaders !== 'function') continue
      return { mod, dir, file: f }
    } catch (e) {
      tried.push('  load error: ' + e.message)
    }
  }
  throw new Error('bdms.node not found. tried:\n' + tried.join('\n'))
}

const native = loadNative()
const bdms = native.mod
try { bdms.init({ deviceId: DEVICE_ID }) } catch (e) { console.error('[signer] bdms.init failed:', e.message) }

/* ---------- 公共参数 ---------- */

function commonParams(overrides = {}) {
  return Object.assign({
    aid: '386088',
    app_name: 'luna_pc',
    region: 'cn',
    geo_region: 'cn',
    os_region: 'cn',
    sim_region: '',
    device_id: DEVICE_ID,
    cdid: '',
    iid: INSTALL_ID,
    version_name: APP_VERSION,
    version_code: VERSION_CODE,
    channel: CHANNEL,
    build_mode: 'official',
    network_carrier: '',
    ac: 'wifi',
    tz_name: 'Asia/Shanghai',
    resolution: '',
    device_platform: 'windows',
    device_type: 'Windows',
    os_version: OS_VERSION,
    fp: DEVICE_ID,
  }, overrides || {})
}

function withCommonParams(rawUrl, extra) {
  const u = new URL(rawUrl)
  const p = commonParams(extra)
  for (const [k, v] of Object.entries(p)) {
    if (v === undefined || v === null) continue
    if (!u.searchParams.has(k)) u.searchParams.set(k, String(v))
  }
  return u
}

/* ---------- 签名 ---------- */

function signUrl(rawUrl, headers) {
  const lines = []
  for (const [k, v] of Object.entries(headers)) {
    const vals = Array.isArray(v) ? v : [v]
    for (const one of vals) lines.push(`${k}\r\n${one}`)
  }
  const sig = bdms.generateHttpSignatureHeaders(rawUrl, lines.join('\r\n'))
  const parts = String(sig).split('\r\n').filter(t => t.trim())
  const out = {}
  for (let i = 0; i + 1 < parts.length; i += 2) out[parts[i]] = parts[i + 1]
  return out
}

/* ---------- 转发 ---------- */

function doRequest({ method = 'GET', url, headers = {}, body = null, binary = false, timeout = 20000 }) {
  return new Promise((resolve, reject) => {
    const u = new URL(url)
    const isHttps = u.protocol === 'https:'
    const lib = isHttps ? https : http
    const req = lib.request({
      protocol: u.protocol,
      hostname: u.hostname,
      port: u.port || (isHttps ? 443 : 80),
      path: u.pathname + u.search,
      method,
      headers,
      timeout,
    }, (res) => {
      const chunks = []
      res.on('data', c => chunks.push(c))
      res.on('end', () => {
        const buf = Buffer.concat(chunks)
        resolve({
          status: res.statusCode,
          headers: res.headers,
          body: binary ? null : buf.toString('utf8'),
          base64: binary ? buf.toString('base64') : undefined,
          setCookie: res.headers['set-cookie'] || [],
        })
      })
    })
    req.on('timeout', () => { req.destroy(new Error('timeout')) })
    req.on('error', reject)
    if (body) req.write(body)
    req.end()
  })
}

/* ---------- bdticket / ticket_guard ---------- */
/*
 * 官方 ticket_guard 客户端：给请求加 bd-ticket-guard-* 头，并维护设备证书。
 * 客户端实现见 app.asar 里的 src/libs/bdticket/index.ts：
 *     bdticket.init(); bdticket.refreshSettings(DEFAULT_SETTINGS); bdticket.startBDTicket(userData)
 *     const r = bdticket.handleRequest(host, pathname, headers, sessionID, sessionSS)
 *     bdticket.handleResponse(host, pathname, resHeaders, respSessionID,
 *                             requestHeaders, sessionID, sessionSS, r.associated)
 * 顺序：先 bdms 签名，再 bdticket 签名。
 */

const BT_SETTINGS = {
  session_guard_config: {
    enable: true,
    ree_path: [
      '/luna/pc/me/collection/media',
      '/luna/pc/user/me/privacy/setting',
      '/luna/pc/me/collection/playlist',
      '/luna/pc/me/collection/album',
      '/luna/pc/me/follow',
      '/luna/pc/me/collection/artist',
      '/webcast/room/create/',
      '/web/api/media/user/info/',
      '/passport/token/beat/web/',
    ],
    ree_path_prefix: [],
    ree_exclude_path: [],
    ree_exclude_path_prefix: [],
    ree_enable_symmetric: true,
    config_version: '20250908.1',
  },
  enable_full_path_track: true,
}

let bdticket = null
let bdticketError = null

function guessUserData() {
  if (process.env.QSM_USER_DATA) return process.env.QSM_USER_DATA
  const appdata = process.env.APPDATA || ''
  return path.join(appdata, 'SodaMusic')
}

function cookieValue(cookieStr, name) {
  if (!cookieStr) return ''
  for (const part of String(cookieStr).split(';')) {
    const i = part.indexOf('=')
    if (i < 0) continue
    if (part.slice(0, i).trim() === name) return part.slice(i + 1).trim()
  }
  return ''
}

function setCookieValue(setCookies, name) {
  for (const line of setCookies || []) {
    const first = String(line).split(';')[0]
    const i = first.indexOf('=')
    if (i < 0) continue
    if (first.slice(0, i).trim() === name) return first.slice(i + 1).trim()
  }
  return ''
}

function initBdticket() {
  if (process.env.QSM_BDTICKET === '0') {
    bdticketError = 'disabled by env'
    return null
  }
  for (const dir of candidateDirs()) {
    const f = path.join(dir, 'bdticket.node')
    if (!fs.existsSync(f)) continue
    try {
      process.chdir(dir)
      const bt = require(f)
      bt.registerEventEmitter(onBdticketEvent)
      bt.refreshSettings(BT_SETTINGS)
      const ok = bt.startBDTicket(guessUserData())
      bdticket = bt
      bdticketError = null
      console.log(`[signer] bdticket ready=${ok} userData=${guessUserData()}`)
      return bt
    } catch (e) {
      bdticketError = e.message
    }
  }
  if (!bdticketError) bdticketError = 'bdticket.node not found'
  return null
}

function onBdticketEvent(event, ...args) {
  if (event === 'pc_request_cert') {
    const [data, callback] = args
    const form = data && Object.keys(data).length ? data : { server_data: '1' }
    const qs = new URLSearchParams(Object.assign(commonParams(), {
      aid: APP_ID,
      is_from_ttaccountsdk: '1',
    })).toString()
    const body = new URLSearchParams(form).toString()
    doRequest({
      method: 'POST',
      url: `https://api.qishui.com/passport/ticket_guard/get_client_cert/?${qs}`,
      headers: {
        'user-agent': `LunaPC/${APP_VERSION}(${BUILD_ID})`,
        'content-type': 'application/x-www-form-urlencoded',
        'content-length': Buffer.byteLength(body),
      },
      body,
    }).then((r) => {
      let parsed = {}
      try { parsed = JSON.parse(r.body) } catch (e) { }
      callback({ data: parsed }, (r.headers && r.headers['x-tt-logid']) || '', { httpStatusCode: 0, message: '' })
    }).catch((e) => {
      callback({ data: {} }, '', { httpStatusCode: 500, message: e.message })
    })
  } else if (event === 'log_error') {
    console.error('[bdticket] ' + String(args[0] || ''))
  }
}

/* ---------- spade 解密（音频 CENC key） ---------- */

let deviceMod = null
function getDevice() {
  if (deviceMod) return deviceMod
  for (const dir of candidateDirs()) {
    const f = path.join(dir, 'device.node')
    if (!fs.existsSync(f)) continue
    try {
      process.chdir(dir)
      deviceMod = require(f)
      return deviceMod
    } catch (e) { /* try next */ }
  }
  throw new Error('device.node not found')
}

/* ---------- HTTP server ---------- */

function readBody(req) {
  return new Promise((resolve, reject) => {
    const chunks = []
    req.on('data', c => chunks.push(c))
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8')
      if (!raw) return resolve({})
      try { resolve(JSON.parse(raw)) } catch (e) { reject(e) }
    })
    req.on('error', reject)
  })
}

function send(res, code, obj) {
  const data = Buffer.from(JSON.stringify(obj), 'utf8')
  res.writeHead(code, { 'content-type': 'application/json; charset=utf-8', 'content-length': data.length })
  res.end(data)
}

const server = http.createServer(async (req, res) => {
  try {
    const url = new URL(req.url, `http://${HOST}:${PORT}`)

    if (url.pathname === '/health') {
      return send(res, 200, {
        ok: true,
        deviceId: DEVICE_ID,
        installId: INSTALL_ID,
        version: APP_VERSION,
        versionCode: VERSION_CODE,
        native: native.file,
        node: process.versions.node,
        electron: process.versions.electron || null,
        bdticket: bdticket ? { ready: true, userData: guessUserData() } : { ready: false, error: bdticketError },
      })
    }

    if (url.pathname === '/sign' && req.method === 'POST') {
      const { url: target, headers } = await readBody(req)
      if (!target) return send(res, 400, { ok: false, error: 'url required' })
      const finalUrl = withCommonParams(target).toString()
      const sig = signUrl(finalUrl, headers || {})
      return send(res, 200, { ok: true, url: finalUrl, headers: sig })
    }

    if (url.pathname === '/fetch' && req.method === 'POST') {
      const bodyIn = await readBody(req)
      const { method = 'GET', url: target, headers = {}, body = null, binary = false, timeout } = bodyIn
      // common=false：不注入 luna 公共参数（passport 等接口用官方 aid 自带参数集）
      const injectCommon = bodyIn.common !== false
      if (!target) return send(res, 400, { ok: false, error: 'url required' })
      const finalUrl = injectCommon ? withCommonParams(target).toString() : new URL(target).toString()
      const u = new URL(finalUrl)
      const isPassport = finalUrl.includes('/passport/')
      const isTtwid = finalUrl.includes('/ttwid/')
      const isQishui = finalUrl.includes('qishui.com')

      const h = Object.assign({}, headers)
      // 客户端对 qishui 的非 passport 请求会强制改 UA
      if (isQishui && !isPassport && !isTtwid) {
        h['user-agent'] = `LunaPC/${APP_VERSION}(${BUILD_ID})`
      } else if (!h['user-agent']) {
        h['user-agent'] = `LunaPC/${APP_VERSION}(${BUILD_ID})`
      }
      if (body && !h['content-type']) h['content-type'] = 'application/json; charset=utf-8'
      if (body) h['content-length'] = Buffer.byteLength(body)

      // 1) bdms 签名（passport / ttwid 除外）
      const bdmsSigned = isQishui && !isPassport && !isTtwid
      if (bdmsSigned) Object.assign(h, signUrl(finalUrl, h))

      // 2) bdticket（所有请求）
      let btCtx = null
      const btResult = { applied: false, error: bdticketError }
      if (bdticket) {
        const cookieStr = h['cookie'] || ''
        const sid = cookieValue(cookieStr, 'sessionid')
        const sidSS = cookieValue(cookieStr, 'sessionid_ss')
        try {
          const r = bdticket.handleRequest(u.hostname, u.pathname, h, sid, sidSS) || {}
          const add = r.headers || {}
          Object.assign(h, add)
          btCtx = {
            headers: Object.assign({}, h),
            sid, sidSS,
            associated: r.associated || {},
          }
          btResult.applied = true
          btResult.headers = add
        } catch (e) {
          btResult.error = e.message
        }
      }

      const result = await doRequest({ method, url: finalUrl, headers: h, body, binary, timeout })

      // 3) 响应回写 ticket 状态
      if (bdticket && btCtx) {
        try {
          const respSid = setCookieValue(result.setCookie, 'sessionid')
          bdticket.handleResponse(
            u.hostname, u.pathname, result.headers, respSid,
            btCtx.headers, btCtx.sid, btCtx.sidSS, btCtx.associated
          )
        } catch (e) { /* ignore */ }
      }

      return send(res, 200, { ok: true, url: finalUrl, bdms: bdmsSigned, bdticket: btResult, ...result })
    }

    if (url.pathname === '/spade' && req.method === 'POST') {
      const { spade_a, kid, key_seed } = await readBody(req)
      if (!spade_a) return send(res, 400, { ok: false, error: 'spade_a required' })
      const dev = getDevice()
      const key = dev.decodeSpade(String(spade_a))
      return send(res, 200, { ok: true, spade_a, kid: kid || null, key, keyHex: String(key) })
    }

    send(res, 404, { ok: false, error: 'not found' })
  } catch (e) {
    send(res, 500, { ok: false, error: e.message, stack: String(e.stack || '') })
  }
})

server.listen(PORT, HOST, () => {
  console.log(`[signer] listening http://${HOST}:${PORT}`)
  console.log(`[signer] native: ${native.file}`)
  console.log(`[signer] app ${APP_VERSION} (${VERSION_CODE}) device=${DEVICE_ID}`)
  try { initBdticket() } catch (e) { bdticketError = e.message }
})
