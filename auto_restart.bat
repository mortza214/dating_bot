@echo off
title Telegram Dating Bot - Auto Restart
color 0A

REM مسیر PHP در XAMPP (در صورت نیاز تغییر بده)
set PHP_PATH=C:\xampp\php\php.exe

REM مسیر فایل اصلی ربات
set BOT_PATH=C:\xampp\htdocs\dating_bot\auto_bot.php

echo ============================================
echo   Telegram Dating Bot - Auto Restart System
echo   Created by Morteza & Tina
echo ============================================
echo.

:loop
echo [%date% %time%] Starting bot...
"%PHP_PATH%" "%BOT_PATH%"
echo [%date% %time%] Bot stopped. Restarting in 5 seconds...
timeout /t 5 /nobreak >nul
goto loop
