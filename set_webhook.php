<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$token = $_ENV['TELEGRAM_BOT_TOKEN'];
$serverIp = '116.203.106.98'; // 🔴 آی‌پی سرور را اینجا قرار بده
$webhookUrl = "http://{$serverIp}/dating_bot/public/index.php";

echo "🔧 Setting webhook for IP: {$serverIp}\n";

// حذف وب‌هوک قبلی
$deleteUrl = "https://api.telegram.org/bot{$token}/deleteWebhook";
$deleteResponse = file_get_contents($deleteUrl);
echo "🗑️ Delete webhook: " . $deleteResponse . "\n";

// تنظیم وب‌هوک جدید
$setUrl = "https://api.telegram.org/bot{$token}/setWebhook?url={$webhookUrl}";
$setResponse = file_get_contents($setUrl);
echo "✅ Set webhook: " . $setResponse . "\n";

// بررسی وضعیت
$infoUrl = "https://api.telegram.org/bot{$token}/getWebhookInfo";
$infoResponse = file_get_contents($infoUrl);
echo "📊 Webhook info: " . $infoResponse . "\n";