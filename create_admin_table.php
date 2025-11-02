<?php
// create_admin_table_simple.php

// اطلاعات دیتابیس - با اطلاعات خودت جایگزین کن
$host = 'localhost';
$dbname = 'dating_system';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ اتصال به دیتابیس برقرار شد\n";
    
    // ایجاد جدول administrators
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS administrators (
            id INT PRIMARY KEY AUTO_INCREMENT,
            telegram_id BIGINT UNIQUE NOT NULL,
            username VARCHAR(255) NULL,
            first_name VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    
    echo "✅ جدول administrators ایجاد شد\n";
    
    // اضافه کردن کاربر اصلی
    $superAdminId =  81650417; // 👈 این رو عوض کن به آیدی تلگرام خودت
    
    try {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO administrators (telegram_id, username, first_name) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$superAdminId, 'superadmin', 'Super Admin']);
        
        echo "✅ کاربر سوپر ادمین اضافه شد (آیدی: {$superAdminId})\n";
        
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // خطای duplicate
            echo "⚠️ کاربر سوپر ادمین از قبل وجود دارد\n";
        } else {
            throw $e;
        }
    }
    
    echo "🎉 سیستم مدیریت آماده است!\n";
    
} catch (PDOException $e) {
    echo "❌ خطا: " . $e->getMessage() . "\n";
    echo "📍 کد خطا: " . $e->getCode() . "\n";
}