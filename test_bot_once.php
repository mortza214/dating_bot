<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Core\BotCore;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Database configuration
$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'] ?? 'localhost',
    'database'  => $_ENV['DB_NAME'] ?? 'dating_bot',
    'username'  => $_ENV['DB_USER'] ?? 'root',
    'password'  => $_ENV['DB_PASS'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
]);


$capsule->setAsGlobal();
$capsule->bootEloquent();

echo "🤖 Starting Single Bot Test...\n";
echo "==============================\n";

try {
    $bot = new BotCore();
    
    // فقط یکبار اجرا کن
    $bot->handleUpdate();
    
    echo "✅ Bot test completed!\n";
    echo "💡 Now send a NEW message to your bot in Telegram\n";
    echo "💡 Then run this again to see only the NEW message\n";
    
} catch (\Exception $e) {
    echo "❌ Bot test failed: " . $e->getMessage() . "\n";
}