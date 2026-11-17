@echo off
chcp 65001 >nul
setlocal

rem 一键启动：签名 sidecar + Web 控制台

set "ROOT=%~dp0"

start "qsm-signer" cmd /c ""%ROOT%start-signer.cmd""
timeout /t 5 /nobreak >nul
start "qsm-web" cmd /c ""%ROOT%start-web.cmd""

timeout /t 3 /nobreak >nul
start "" "http://127.0.0.1:8899"

endlocal
