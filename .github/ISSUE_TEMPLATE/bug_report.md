---
name: Bug 报告
about: 功能不对、报错、拿到试听片段
title: '[bug] '
labels: bug
assignees: ''
---

## 现象

<!-- 一句话说清哪里不对 -->

## 复现步骤

```
1.
2.
3.
```

## 期望 vs 实际

**期望**：

**实际**：

## 环境

| 项 | 值 |
|---|---|
| 客户端版本 | <!-- resources\app\package.json 里的 version --> |
| PHP 版本 | <!-- runtime\php\php.exe -v --> |
| 账号状态 | 会员 / 非会员 / 匿名 |
| 用的入口 | CLI `bin\qsmusic` / Web 控制台 / `api.php` |

## 报错与日志

<!-- 粘贴输出，cookie 和 sessionid 请打码 -->

```
```

## 附带的诊断信息

```
runtime\php\php.exe bin\qsmusic health
```

```json

```

## 补充

<!-- 如果涉及某首特定的歌，给出曲目 ID；如果是试听片段问题，附上
     api.php?action=song&id=... 里 is_preview / preview_note 的值 -->
