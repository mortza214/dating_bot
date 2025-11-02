<?php
require_once __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;
use App\Core\BotCore;

// بررسی وجود فایل .env و بارگذاری آن
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
} else {
    echo "⚠️  فایل .env یافت نشد. از مقادیر پیش‌فرض استفاده می‌شود.\n";
    
    // تنظیم مقادیر پیش‌فرض
    $_ENV['DB_HOST'] = 'localhost';
    $_ENV['DB_NAME'] = 'dating_system'; // نام دیتابیس شما
    $_ENV['DB_USER'] = 'root';
    $_ENV['DB_PASS'] = '';
    $_ENV['TELEGRAM_BOT_TOKEN'] = 'your_bot_token_here';
}

// Database configuration
$capsule = new Capsule;
$capsule->addConnection([
    'driver'    => 'mysql',
    'host'      => $_ENV['DB_HOST'] ?? 'localhost',
    'database'  => $_ENV['DB_NAME'] ?? 'dating_system', // استفاده از نام دیتابیس شما
    'username'  => $_ENV['DB_USER'] ?? 'root',
    'password'  => $_ENV['DB_PASS'] ?? '',
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix'    => '',
]);

$capsule->setAsGlobal();
$capsule->bootEloquent();

echo "🤖 Starting Auto Bot...\n";
echo "=======================\n";
echo "📊 Database: " . ($_ENV['DB_NAME'] ?? 'dating_system') . "\n";
echo "🌐 Host: " . ($_ENV['DB_HOST'] ?? 'localhost') . "\n";

try {
    $bot = new BotCore();
    
    // اجرای پیوسته ربات (هر ۲ ثانیه)
    while (true) {
        $bot->handleUpdate();
        sleep(2); // تأخیر ۲ ثانیه بین چک‌های متوالی
    }
    
} catch (\Exception $e) {
    echo "❌ Bot failed: " . $e->getMessage() . "\n";
    echo "📋 Stack trace: " . $e->getTraceAsString() . "\n";
}