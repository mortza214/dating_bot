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

echo "🤖 Starting Auto Bot...\n";
echo "=======================\n";

try {
    $bot = new BotCore();
    
    // اجرای پیوسته ربات (هر ۲ ثانیه)
    while (true) {
        $bot->handleUpdate();
        sleep(2); // تأخیر ۲ ثانیه بین چک‌های متوالی
    }
    
} catch (\Exception $e) {
    echo "❌ Bot failed: " . $e->getMessage() . "\n";
}