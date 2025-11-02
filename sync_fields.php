<?php
// simple_sync.php

// تنظیمات دیتابیس - اینها را با اطلاعات دیتابیس خودت جایگزین کن
$db_host = 'localhost';
$db_name = 'dating_system';
$db_user = 'root';
$db_pass = '';

try {
    // اتصال به دیتابیس
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ اتصال به دیتابیس برقرار شد\n\n";
    
    // فیلدهایی که باید در جدول users باشند
    $requiredFields = [
        'bio' => 'TEXT NULL',
        'height' => 'INT NULL',
        'weight' => 'INT NULL', 
        'mobile' => 'VARCHAR(255) NULL',
        'education' => 'VARCHAR(255) NULL',
        'job' => 'VARCHAR(255) NULL',
        'income_level' => 'VARCHAR(255) NULL',
        'city' => 'VARCHAR(255) NULL',
        'age' => 'INT NULL',
        'gender' => 'VARCHAR(50) NULL',
        'marital_status' => 'VARCHAR(50) NULL',
        'religion' => 'VARCHAR(100) NULL',
        'smoking' => 'VARCHAR(50) NULL',
        'children' => 'VARCHAR(50) NULL',
        'relationship_goal' => 'VARCHAR(100) NULL',
        'is_profile_completed' => 'TINYINT(1) DEFAULT 0',
		'fother_job' => 'VARCHAR(255) NULL',
        'health_status' => 'VARCHAR(255) NULL',
        'photo' => 'VARCHAR(255) NULL'
    ];
    
    // بررسی فیلدهای موجود
    $stmt = $pdo->query("SHOW COLUMNS FROM users");
    $existingColumns = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    echo "📋 فیلدهای موجود در جدول users:\n";
    foreach ($existingColumns as $column) {
        echo " - $column\n";
    }
    
    echo "\n🔍 بررسی فیلدهای مورد نیاز...\n";
    $missingFields = [];
    
    foreach ($requiredFields as $fieldName => $fieldType) {
        if (!in_array($fieldName, $existingColumns)) {
            $missingFields[$fieldName] = $fieldType;
            echo "❌ فیلد missing: $fieldName\n";
        } else {
            echo "✅ فیلد موجود: $fieldName\n";
        }
    }
    
    if (empty($missingFields)) {
        echo "\n🎉 همه فیلدها در دیتابیس وجود دارند!\n";
        exit;
    }
    
    echo "\n🔧 اضافه کردن فیلدهای missing...\n";
    
    foreach ($missingFields as $fieldName => $fieldType) {
        try {
            $sql = "ALTER TABLE users ADD COLUMN $fieldName $fieldType";
            $pdo->exec($sql);
            echo "✅ فیلد $fieldName اضافه شد\n";
        } catch (PDOException $e) {
            echo "❌ خطا در اضافه کردن $fieldName: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n🎉 عملیات تکمیل شد!\n";
    
} catch (PDOException $e) {
    echo "❌ خطا در اتصال به دیتابیس: " . $e->getMessage() . "\n";
    
    // نمایش دیتابیس‌های موجود برای کمک به عیب‌یابی
    try {
        $pdo_temp = new PDO("mysql:host=$db_host;charset=utf8mb4", $db_user, $db_pass);
        $stmt = $pdo_temp->query("SHOW DATABASES");
        $databases = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        echo "\n📋 دیتابیس‌های موجود:\n";
        foreach ($databases as $db) {
            echo " - $db\n";
        }
    } catch (PDOException $e2) {
        echo "خطا در دریافت لیست دیتابیس‌ها: " . $e2->getMessage() . "\n";
    }
}