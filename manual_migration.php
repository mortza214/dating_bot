<?php
// manual_migration.php
require_once __DIR__ . '/app/Models/User.php';
require_once __DIR__ . '/bootstrap/database.php'; // اگر فایل اتصال دیتابیس دارید

echo "Starting manual migration...\n";

try {
    // اتصال به دیتابیس
    $host = 'localhost';
    $dbname = 'dating_system'; // نام دیتابیس خود را وارد کنید
    $username = 'root'; // کاربر دیتابیس
    $password = ''; // رمز دیتابیس
    
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // بررسی وجود جدول users
    $tableExists = $pdo->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
    
    if (!$tableExists) {
        echo "❌ Table 'users' does not exist!\n";
        exit;
    }
    
    // بررسی وجود فیلدها
    $columns = $pdo->query("SHOW COLUMNS FROM users")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('profile_photo', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL");
        echo "✅ Added profile_photo column\n";
    } else {
        echo "ℹ️ profile_photo column already exists\n";
    }
    
    if (!in_array('profile_photos', $columns)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN profile_photos JSON NULL");
        echo "✅ Added profile_photos column\n";
    } else {
        echo "ℹ️ profile_photos column already exists\n";
    }
    
    echo "🎉 Migration completed successfully!\n";
    
} catch (PDOException $e) {
    echo "❌ Migration failed: " . $e->getMessage() . "\n";
    echo "Please check your database configuration.\n";
}