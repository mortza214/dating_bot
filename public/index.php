<?php
// دیباگ: بررسی وجود فایل‌ها
echo "🔍 Debug: Starting...<br>";

$rootDir = __DIR__ . '/..';

// بررسی وجود vendor/autoload.php
$autoloadPath = $rootDir . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die("❌ vendor/autoload.php not found at: " . $autoloadPath);
}
echo "✅ vendor/autoload.php found<br>";

require_once $autoloadPath;

use Dotenv\Dotenv;
use Illuminate\Database\Capsule\Manager as Capsule;

// بررسی وجود فایل .env
$envPath = $rootDir . '/.env';
if (!file_exists($envPath)) {
    die("❌ .env file not found at: " . $envPath);
}
echo "✅ .env file found<br>";

// بارگیری متغیرهای محیطی
$dotenv = Dotenv::createImmutable($rootDir);
$dotenv->load();

echo "✅ Environment variables loaded<br>";

// دیباگ: نمایش متغیرهای بارگیری شده
echo "DB_HOST: " . ($_ENV['DB_HOST'] ?? 'NOT_SET') . "<br>";
echo "DB_USER: " . ($_ENV['DB_USER'] ?? 'NOT_SET') . "<br>";
echo "DB_PASS: " . ($_ENV['DB_PASS'] ?? 'NOT_SET') . "<br>";
echo "DB_NAME: " . ($_ENV['DB_NAME'] ?? 'NOT_SET') . "<br>";

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
    Capsule::connection()->getPdo();
    echo "✅ Database connected successfully!<br>";
    
    // اگر به اینجا رسیدی، یعنی دیتابیس وصل شده
    $input = file_get_contents("php://input");
    
    if (!empty($input)) {
        require_once $rootDir . '/app/Core/BotCore.php';
        $bot = new App\Core\BotCore();
        $bot->handleUpdate();
        echo "🤖 Bot is processing update...";
    } else {
        echo "🎉 Everything is working! Bot is ready for Telegram messages.";
    }
    
} catch (\Exception $e) {
    echo "❌ Database Error: " . $e->getMessage() . "<br>";
    echo "💡 Check your database settings in .env file";
}