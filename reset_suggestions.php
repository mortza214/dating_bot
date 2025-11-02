<?php
// reset_suggestions.php
// قرار دادن این فایل در پوشه اصلی پروژه (همان پوشه‌ای که فایل test_bot_once.php قرار دارد)

require_once __DIR__ . '/vendor/autoload.php';
// یا اگر vendor در مسیر دیگری است:
// require_once __DIR__ . '/../vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as DB;

try {
    echo "🔄 شروع ریست پیشنهادات و تاریخچه...\n";
    
    // اتصال به دیتابیس
    $pdo = new PDO("mysql:host=localhost;dbname=dating_system;charset=utf8mb4", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. ریست تمام پیشنهادات نمایش داده شده
    $stmt = $pdo->prepare("TRUNCATE TABLE user_suggestions");
    $stmt->execute();
    echo "✅ جدول user_suggestions ریست شد\n";
    
    // 2. ریست تاریخچه درخواست‌های تماس (اختیاری)
    // $stmt = $pdo->prepare("TRUNCATE TABLE contact_request_history");
    // $stmt->execute();
    // echo "✅ جدول contact_request_history ریست شد\n";
    
    // 3. نمایش تعداد کاربران موجود
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM users WHERE is_profile_completed = 1");
    $stmt->execute();
    $totalUsers = $stmt->fetch(PDO::FETCH_OBJ)->total;
    
    echo "📊 کاربران با پروفایل کامل: {$totalUsers} نفر\n";
    
    echo "🎉 ریست با موفقیت انجام شد!\n";
    echo "✅ اکنون می‌توانید دوباره از بخش 'دریافت پیشنهاد' استفاده کنید\n";
    
} catch (Exception $e) {
    echo "❌ خطا در ریست: " . $e->getMessage() . "\n";
    echo "💡 راهنمایی: مطمئن شوید که:\n";
    echo "• دیتابیس در حال اجراست\n";
    echo "• نام دیتابیس و کاربر/رمز عبور صحیح است\n";
    echo "• فایل در پوشه correct قرار دارد\n";
}