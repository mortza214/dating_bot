<?php
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

$token = $_ENV['TELEGRAM_BOT_TOKEN'];
$webhookUrl = 'http://localhost/dating_bot/public/index.php';

$url = "https://api.telegram.org/bot{$token}/setWebhook?url={$webhookUrl}";
$response = file_get_contents($url);

echo "Webhook setup response: " . $response;