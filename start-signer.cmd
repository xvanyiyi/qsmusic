@echo off
chcp 65001 >nul
setlocal

rem ============================================================
rem  启动汽水音乐签名 sidecar
rem  用自带的 Electron 运行时（signer\electron）加载官方 bdms.node
rem ============================================================

set "ROOT=%~dp0"
set "ELECTRON_RUN_AS_NODE=1"

if not exist "%ROOT%signer\electron\qsm-signer.exe" (
  echo [错误] 缺少 signer\electron\qsm-signer.exe
  echo        这个 Electron 运行时来自汽水音乐安装包，是跑 bdms.node / bdticket.node 所需的。
  echo        注意：必须叫 qsm-signer.exe，不能叫 SodaMusic.exe —— 否则会与汽水音乐
  echo        客户端的单实例检测冲突，客户端会起来就退出。
  exit /b 1
)

rem 客户端原生库目录（自动探测失败时手动指定）
if "%QSM_NATIVE_DIR%"=="" (
  if exist "D:\qishui\Soda Music\3.5.1\resources\app.asar.unpacked" (
    set "QSM_NATIVE_DIR=D:\qishui\Soda Music\3.5.1\resources\app.asar.unpacked"
  )
)

if "%QSM_SIGNER_PORT%"=="" set "QSM_SIGNER_PORT=8791"

echo [signer] 端口 %QSM_SIGNER_PORT%
if not "%QSM_NATIVE_DIR%"=="" echo [signer] 原生库 %QSM_NATIVE_DIR%
echo [signer] 启动中...

"%ROOT%signer\electron\qsm-signer.exe" "%ROOT%signer\server.js"

endlocal
