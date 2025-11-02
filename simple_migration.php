<?php
// simple_migration.php

echo "Starting simple migration...\n";

try {
    // تنظیمات دیتابیس - این مقادیر را با تنظیمات خود جایگزین کنید
    $host = 'localhost';
    $dbname = 'dating_system'; // نام دیتابیس خود را وارد کنید
    $username = 'root'; // کاربر دیتابیس
    $password = ''; // رمز دیتابیس
    
    // اتصال به دیتابیس
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Connected to database successfully\n";
    
    // ۱. بررسی وجود جدول users
    $tableExists = $pdo->query("SHOW TABLES LIKE 'users'")->rowCount() > 0;
    
    if (!$tableExists) {
        echo "❌ Table 'users' does not exist! Creating table...\n";
        
        // ایجاد جدول users اگر وجود ندارد
        $sql = "CREATE TABLE users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            telegram_id BIGINT UNIQUE,
            first_name VARCHAR(255),
            last_name VARCHAR(255),
            username VARCHAR(255),
            state VARCHAR(50),
            is_profile_completed BOOLEAN DEFAULT FALSE,
            profile_photo VARCHAR(255) NULL,
            profile_photos JSON NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        
        $pdo->exec($sql);
        echo "✅ Created users table\n";
    } else {
        echo "ℹ️ Users table exists\n";
    }
    
    // ۲. اضافه کردن فیلدهای جدید اگر وجود ندارند
    $stmt = $pdo->query("DESCRIBE users");
    $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
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
    echo "Make sure:\n";
    echo "1. MySQL is running\n";
    echo "2. Database '{$dbname}' exists\n";
    echo "3. Username and password are correct\n";
}