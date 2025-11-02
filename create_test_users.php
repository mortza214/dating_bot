<?php
// create_simple_test_users.php
// ایجاد ساده ۱۰ کاربر تستی

try {
    echo "🚀 شروع ایجاد کاربران تستی ساده...\n\n";
    
    // تنظیمات دیتابیس
    $host = 'localhost';
    $dbname = 'dating_system';
    $username = 'root';
    $password = '';
    
    // اتصال به دیتابیس
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // لیست شهرها
    $cities = ['تهران', 'مشهد', 'اصفهان', 'شیراز', 'تبریز', 'کرج', 'قم', 'اهواز'];
    
    // کاربران تستی
    $testUsers = [
        ['name' => 'TEST_علی', 'gender' => 'مرد'],
        ['name' => 'TEST_رضا', 'gender' => 'مرد'],
        ['name' => 'TEST_محمد', 'gender' => 'مرد'],
        ['name' => 'TEST_امیر', 'gender' => 'مرد'],
        ['name' => 'TEST_حسین', 'gender' => 'مرد'],
        ['name' => 'TEST_فاطمه', 'gender' => 'زن'],
        ['name' => 'TEST_زهرا', 'gender' => 'زن'],
        ['name' => 'TEST_مریم', 'gender' => 'زن'],
        ['name' => 'TEST_سارا', 'gender' => 'زن'],
        ['name' => 'TEST_نازنین', 'gender' => 'زن']
    ];
    
    $createdCount = 0;
    
    echo "👥 ایجاد " . count($testUsers) . " کاربر تستی...\n\n";
    
    foreach ($testUsers as $index => $user) {
        $telegramId = 1000000000 + $index;
        $username = "test_user_" . ($index + 1);
        $age = rand(22, 35);
        $city = $cities[array_rand($cities)];
        
        // کوئری ساده با فیلدهای اصلی
        $sql = "INSERT INTO users (
            telegram_id, username, first_name, last_name, 
            gender, age, city, state,
            is_profile_completed, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'start', 1, NOW(), NOW())";
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([
            $telegramId,
            $username,
            $user['name'],
            'TEST',
            $user['gender'],
            $age,
            $city
        ]);
        
        if ($result) {
            echo "✅ {$user['name']} - {$user['gender']} - سن: {$age} - شهر: {$city}\n";
            $createdCount++;
        } else {
            echo "❌ خطا در ایجاد {$user['name']}\n";
        }
    }
    
    echo "\n🎉 ایجاد {$createdCount} کاربر تستی با موفقیت انجام شد!\n";
    
} catch (Exception $e) {
    echo "❌ خطا: " . $e->getMessage() . "\n";
}