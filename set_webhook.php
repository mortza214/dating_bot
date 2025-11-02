<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use App\Core\TelegramAPI;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$telegram = new TelegramAPI($_ENV['TELEGRAM_BOT_TOKEN']);

try {
    $result = $telegram->deleteWebhook();
    
    if ($result && $result['ok']) {
        echo "✅ Webhook deleted. Using getUpdates method for development.\n";
        echo "🤖 Bot is ready! Test it in Telegram.\n";
    } else {
        echo "❌ Failed to delete webhook.\n";
    }
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}