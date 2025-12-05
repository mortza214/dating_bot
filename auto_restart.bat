@echo off
chcp 65001 >nul

title Telegram Dating Bot - Auto Restart
color 0A

set "PHP_PATH=C:\xampp\php\php.exe"
set "BOT_PATH=C:\xampp\htdocs\dating_bot\auto_bot.php"

echo ============================================
echo Telegram Dating Bot - Auto Restart System
echo Created by Morteza & Tina
echo ============================================
echo.

:loop
echo [%date% %time%] ربات در حال اجرا...
"%PHP_PATH%" "%BOT_PATH%"
echo [%date% %time%] ربات متوقف شد. شروع مجدد در 5 ثانیه...
timeout /t 5 /nobreak >nul
goto loop
