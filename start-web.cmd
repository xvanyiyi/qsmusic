@echo off
chcp 65001 >nul
setlocal

rem ============================================================
rem  启动 Web 控制台（PHP 内置服务器）
rem  先确保 start-signer.cmd 已经在另一个窗口跑起来
rem ============================================================

set "ROOT=%~dp0"
if "%QSM_WEB_PORT%"=="" set "QSM_WEB_PORT=8899"

where php >nul 2>nul
if errorlevel 1 (
  if exist "%ROOT%runtime\php\php.exe" (
    set "PHP=%ROOT%runtime\php\php.exe"
  ) else (
    echo [错误] 找不到 php，请把 PHP 8.1+ 加入 PATH，或放在 runtime\php\php.exe
    exit /b 1
  )
) else (
  set "PHP=php"
)

echo [web] http://127.0.0.1:%QSM_WEB_PORT%
"%PHP%" -S 127.0.0.1:%QSM_WEB_PORT% -t "%ROOT%public"

endlocal
