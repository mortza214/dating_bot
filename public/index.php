<?php
// public/index.php

$rootDir = __DIR__ . '/..';

require_once $rootDir . '/vendor/autoload.php';

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

// بارگیری متغیرهای محیطی
$dotenv = Dotenv::createImmutable($rootDir);
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

try {
    // تست اتصال دیتابیس
    Capsule::connection()->getPdo();
    
    $input = file_get_contents("php://input");
    
    if (!empty($input)) {
        require_once $rootDir . '/app/Core/BotCore.php';
        $bot = new App\Core\BotCore();
        
        // پردازش وب‌هوک
        $update = json_decode($input, true);
        
        if (isset($update['message'])) {
            $bot->handleMessage($update['message']);
        } elseif (isset($update['callback_query'])) {
            $bot->processCallbackQuery($update['callback_query']);
        }
        
    } else {
        // حالت تست - فقط برای توسعه
        echo "🤖 Bot is ready! Webhook URL: " . ($_ENV['APP_URL'] ?? '') . "/public/index.php";
    }
    
} catch (\Exception $e) {
    error_log("❌ Error: " . $e->getMessage());
    
    // فقط در حالت توسعه خطا نمایش داده شود
    if (empty(file_get_contents("php://input"))) {
        echo "❌ Error: " . $e->getMessage();
    }
}