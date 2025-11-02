<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Core\BotCore;

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Database configuration
$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'],
    'database'  => $_ENV['DB_NAME'],
    'username'  => $_ENV['DB_USER'],
    'password'  => $_ENV['DB_PASS'],
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();

// Test database connection
try {
    Capsule::connection()->getPdo();
    echo "✅ Database connected successfully!<br>";
    
    // راه اندازی ربات
    $bot = new BotCore();
    $input = file_get_contents("php://input");
    
    if (!empty($input)) {
        // حالت Webhook - برای تولید
        $bot->handleUpdate();
        echo "🤖 Bot is processing update...";
    } else {
        // حالت تست - برای توسعه
        echo "🤖 Bot core is ready!<br>";
        echo "📝 Add your bot token to .env file<br>";
        echo "🚀 Test with: /start in Telegram";
    }
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}