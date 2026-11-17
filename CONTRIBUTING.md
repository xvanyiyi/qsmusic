# 贡献指南

感谢愿意帮忙。这个项目的核心难点是**协议层**，所以最有价值的贡献是新的实测数据，而不是大重构。

## 开发环境

1. PHP 8.1+（启用 `curl` `openssl` `sqlite3` `mbstring`）
2. 安装汽水音乐 PC 客户端（签名用的原生库从它里面读）
3. 按 README 3.1 准备好 `runtime/php/` 和 `signer/electron/`

```bat
runtime\php\php.exe -l src\Qishui\Cenc.php     :: 语法检查单个文件
runtime\php\php.exe bin\qsmusic health         :: 签名服务连通性
```

## 提交前自检

- [ ] 所有改动的 PHP 文件过 `php -l`
- [ ] `bin\qsmusic health` 正常
- [ ] `bin\qsmusic info <某个曲目ID>` 能列出音源档位
- [ ] **不要把 `storage/`、`runtime/`、`signer/electron/` 提交进来**（`.gitignore` 已覆盖）
- [ ] 不要把真实 cookie、`device_id`、CENC key、VOD 签名 URL 写进代码或文档

## 最有价值的几类贡献

### 1. 客户端版本更新后的协议变化

汽水音乐升级后，接口参数、签名头、`aid` 都可能变。请附上：

- 客户端版本号（`resources\app\package.json` 里的 `version`）
- 失败的请求：完整 URL + 关键请求头（cookie 打码）+ 响应体
- 你观察到的差异

### 2. 二维码登录（`4031`）

目前卡在 passport `verify_center`。已经排除的方向写在 README「已知边界」里，
**请不要重复试这些**：改 `version_code`、换 `device_platform`、换 UA、`aid` 试别的值、
真实 Chrome 直连、只加 `bdticket` 头。

还没验证、可能有戏的方向：

- `verifyFp` / `s_v_web_id` 的生成算法（抖音 `webmssdk.js`）
- 客户端 Cronet 栈（`ttnet.node`）能否在独立线程/进程里驱动
- 抓一次真实客户端 `get_qrcode` 的报文，逐字段对比

### 3. 无损 FLAC 之外的新档位

服务端下发的 `quality` 字符串是可扩展的。遇到没见过的值（如 `dolby`、`atmos`），
请贴 `video_meta` 原文，方便加进 `Config::QUALITY_ORDER`。

## 代码风格

- PHP 8.1+，`declare(strict_types=1)`，PSR-12
- 中文注释，说清「为什么」而不是「做了什么」
- 新增对外接口先改 README 的接口章节

## 提交信息

```
feat: 支持 dolby 档位解析
fix: 试听片段时长判定误报
docs: 补充 ttnet 阻塞事件循环的结论
```

## 许可

提交的代码默认以 [MIT](LICENSE) 授权。
