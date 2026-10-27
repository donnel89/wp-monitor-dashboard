@echo off
chcp 65001 >nul
rem Installs the four marketing agents into %USERPROFILE%\.claude\agents
cd /d "%~dp0"
if not exist "%USERPROFILE%\.claude\agents" mkdir "%USERPROFILE%\.claude\agents"
if not exist "%USERPROFILE%\Documents\marketing" mkdir "%USERPROFILE%\Documents\marketing"
copy /Y "agents\*.md" "%USERPROFILE%\.claude\agents\" >nul
echo.
echo The agents were installed to %USERPROFILE%\.claude\agents :
dir /b "%USERPROFILE%\.claude\agents"
echo.
echo Work folder created: %USERPROFILE%\Documents\marketing
echo Now open Claude Code in that folder and type /agents
echo.
pause
