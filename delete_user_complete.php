<?php
// delete_user_complete.php
// استفاده: delete_user_complete.php?user_id=1 یا delete_user_complete.php?telegram_id=81650417

// تنظیمات دیتابیس - اینجا مقادیر خود را وارد کنید
define('DB_HOST', 'localhost');
define('DB_NAME', 'dating_system');
define('DB_USER', 'root');
define('DB_PASS', '');

// اتصال به دیتابیس
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("❌ خطا در اتصال به دیتابیس: " . $e->getMessage());
}

// تابع برای دریافت ورودی
function getInput($name, $default = '') {
    if (PHP_SAPI === 'cli') {
        // حالت خط فرمان
        global $argv;
        foreach ($argv as $arg) {
            if (strpos($arg, "--$name=") === 0) {
                return substr($arg, strlen("--$name="));
            }
        }
        return $default;
    } else {
        // حالت وب
        return $_GET[$name] ?? $_POST[$name] ?? $default;
    }
}

// تابع برای نمایش پیام
function showMessage($message, $isError = false) {
    if (PHP_SAPI === 'cli') {
        echo ($isError ? "❌ " : "✅ ") . $message . "\n";
    } else {
        echo '<div style="padding: 10px; margin: 10px; border: 2px solid ' . ($isError ? 'red' : 'green') . '; background-color: ' . ($isError ? '#ffebee' : '#e8f5e9') . ';">';
        echo ($isError ? '❌ ' : '✅ ') . $message;
        echo '</div>';
    }
}

// تابع برای پیدا کردن کاربر
function findUser($pdo, $userId = null, $telegramId = null) {
    if ($userId) {
        $sql = "SELECT * FROM users WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        return $stmt->fetch();
    } elseif ($telegramId) {
        $sql = "SELECT * FROM users WHERE telegram_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$telegramId]);
        return $stmt->fetch();
    }
    return null;
}

// تابع برای نمایش فرم
function showForm() {
    echo '<!DOCTYPE html>
    <html lang="fa" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>حذف کامل کاربر</title>
        <style>
            body {
                font-family: Tahoma, sans-serif;
                background-color: #f5f5f5;
                padding: 20px;
                line-height: 1.6;
            }
            .container {
                max-width: 800px;
                margin: 0 auto;
                background: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 0 10px rgba(0,0,0,0.1);
            }
            h1 {
                color: #d32f2f;
                text-align: center;
                border-bottom: 2px solid #d32f2f;
                padding-bottom: 10px;
            }
            .warning {
                background-color: #fff3e0;
                border-right: 5px solid #ff9800;
                padding: 15px;
                margin: 20px 0;
                border-radius: 5px;
            }
            .form-group {
                margin-bottom: 20px;
            }
            label {
                display: block;
                margin-bottom: 5px;
                font-weight: bold;
                color: #333;
            }
            input[type="text"] {
                width: 100%;
                padding: 10px;
                border: 1px solid #ddd;
                border-radius: 5px;
                font-size: 16px;
            }
            input[type="submit"] {
                background-color: #d32f2f;
                color: white;
                border: none;
                padding: 12px 30px;
                font-size: 16px;
                border-radius: 5px;
                cursor: pointer;
                transition: background-color 0.3s;
            }
            input[type="submit"]:hover {
                background-color: #b71c1c;
            }
            .stats {
                background-color: #e8f5e9;
                padding: 15px;
                border-radius: 5px;
                margin: 20px 0;
            }
            .confirm-box {
                background-color: #ffebee;
                padding: 20px;
                border: 2px solid #d32f2f;
                border-radius: 5px;
                margin: 20px 0;
                text-align: center;
            }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>🚨 حذف کامل کاربر از سیستم</h1>
            
            <div class="warning">
                <strong>⚠️ هشدار:</strong> این عمل غیرقابل بازگشت است! تمامی سوابق کاربر شامل پروفایل، لایک‌ها، درخواست‌ها، تراکنش‌ها و سایر اطلاعات به طور کامل حذف خواهند شد.
            </div>
            
            <form method="GET" action="">
                <div class="form-group">
                    <label for="user_id">آیدی کاربر (از جدول users):</label>
                    <input type="text" id="user_id" name="user_id" placeholder="مثال: 1">
                </div>
                
                <div style="text-align: center; margin: 20px 0; font-weight: bold;">یا</div>
                
                <div class="form-group">
                    <label for="telegram_id">آیدی تلگرام کاربر:</label>
                    <input type="text" id="telegram_id" name="telegram_id" placeholder="مثال: 81650417">
                </div>
                
                <div style="text-align: center;">
                    <input type="submit" value="🔍 پیدا کردن کاربر">
                </div>
            </form>
        </div>
    </body>
    </html>';
}

// تابع برای نمایش آمار کاربر
function showUserStats($pdo, $user) {
    $userId = $user['id'];
    
    // آمار از جداول مختلف
    $stats = [];
    
    // 1. contact_requests
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM contact_requests WHERE requester_id = ? OR requested_id = ?");
    $stmt->execute([$userId, $userId]);
    $stats['contact_requests'] = $stmt->fetch()['count'];
    
    // 2. contact_request_history
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM contact_request_history WHERE user_id = ? OR requested_user_id = ?");
    $stmt->execute([$userId, $userId]);
    $stats['contact_request_history'] = $stmt->fetch()['count'];
    
    // 3. likes
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM likes WHERE liker_id = ? OR liked_id = ?");
    $stmt->execute([$userId, $userId]);
    $stats['likes'] = $stmt->fetch()['count'];
    
    // 4. referrals
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM referrals WHERE referrer_id = ? OR referred_id = ?");
    $stmt->execute([$userId, $userId]);
    $stats['referrals'] = $stmt->fetch()['count'];
    
    // 5. user_filters
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_filters WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats['user_filters'] = $stmt->fetch()['count'];
    
    // 6. user_subscriptions
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_subscriptions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats['user_subscriptions'] = $stmt->fetch()['count'];
    
    // 7. user_suggestions
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_suggestions WHERE user_id = ? OR suggested_user_id = ?");
    $stmt->execute([$userId, $userId]);
    $stats['user_suggestions'] = $stmt->fetch()['count'];
    
    // 8. wallets
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM wallets WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats['wallets'] = $stmt->fetch()['count'];
    
    // 9. payment_requests
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM payment_requests WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats['payment_requests'] = $stmt->fetch()['count'];
    
    // 10. transactions
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM transactions WHERE user_id = ?");
    $stmt->execute([$userId]);
    $stats['transactions'] = $stmt->fetch()['count'];
    
    echo '<div class="container">
        <h1>📊 آمار کاربر</h1>
        
        <div class="stats">
            <h3>👤 اطلاعات کاربر:</h3>
            <p><strong>آیدی:</strong> ' . $user['id'] . '</p>
            <p><strong>آیدی تلگرام:</strong> ' . $user['telegram_id'] . '</p>
            <p><strong>یوزرنیم:</strong> ' . ($user['username'] ?? 'ندارد') . '</p>
            <p><strong>نام:</strong> ' . $user['first_name'] . ' ' . ($user['last_name'] ?? '') . '</p>
            <p><strong>تاریخ ثبت‌نام:</strong> ' . $user['created_at'] . '</p>
            <p><strong>وضعیت:</strong> ' . ($user['is_active'] ? 'فعال ✅' : 'غیرفعال ❌') . '</p>
        </div>
        
        <div class="stats">
            <h3>📈 سوابق کاربر:</h3>';
    
    foreach ($stats as $key => $count) {
        if ($count > 0) {
            echo '<p><strong>' . ucfirst(str_replace('_', ' ', $key)) . ':</strong> ' . $count . ' رکورد</p>';
        }
    }
    
    echo '</div>
        
        <div class="confirm-box">
            <h2>🚨 آیا مطمئن هستید؟</h2>
            <p>با تأیید، تمامی ' . array_sum($stats) . ' رکورد فوق به طور کامل حذف خواهند شد.</p>
            
            <form method="POST" action="">
                <input type="hidden" name="user_id" value="' . $user['id'] . '">
                <input type="hidden" name="confirm" value="1">
                <input type="submit" value="🔥 حذف کامل کاربر" style="background-color: #d32f2f; color: white; padding: 15px 40px; font-size: 18px; border: none; border-radius: 5px; cursor: pointer; margin: 10px;">
                <br>
                <a href="' . basename(__FILE__) . '" style="color: #666; text-decoration: none;">❌ انصراف و بازگشت</a>
            </form>
        </div>
    </div>';
}

// تابع برای حذف کامل کاربر
function deleteUserCompletely($pdo, $userId) {
    try {
        $pdo->beginTransaction();
        
        // 1. ذخیره اطلاعات کاربر برای لاگ
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userInfo = $stmt->fetch();
        
        if (!$userInfo) {
            throw new Exception("کاربر یافت نشد");
        }
        
        // 2. حذف از جداولی که ممکن است CASCADE نباشند
        // contact_request_history ممکن است CASCADE نباشد
        $tables = [
            'contact_request_history' => ['user_id', 'requested_user_id'],
        ];
        
        foreach ($tables as $table => $columns) {
            foreach ($columns as $column) {
                $sql = "DELETE FROM $table WHERE $column = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$userId]);
            }
        }
        
        // 3. به‌روزرسانی کاربران معرفی شده توسط این کاربر
        $sql = "UPDATE users SET referred_by = NULL WHERE referred_by = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        
        // 4. حذف کاربر از جدول users (بقیه جداول با CASCADE حذف می‌شوند)
        $sql = "DELETE FROM users WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        
        // 5. حذف فایل‌های عکس کاربر (اگر وجود دارد)
        deleteUserFiles($userInfo);
        
        $pdo->commit();
        
        return [
            'success' => true,
            'message' => 'کاربر و تمام سوابقش با موفقیت حذف شدند.',
            'user_info' => $userInfo
        ];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        return [
            'success' => false,
            'message' => 'خطا در حذف کاربر: ' . $e->getMessage()
        ];
    }
}

// تابع برای حذف فایل‌های کاربر
function deleteUserFiles($userInfo) {
    $uploadDir = __DIR__ . '/uploads/profile_photos/';
    
    if (!empty($userInfo['profile_photo'])) {
        $photoPath = $uploadDir . basename($userInfo['profile_photo']);
        if (file_exists($photoPath)) {
            unlink($photoPath);
        }
    }
}

// اجرای اصلی برنامه
if (PHP_SAPI === 'cli') {
    // حالت خط فرمان
    echo "🔥 ابزار حذف کامل کاربر\n";
    echo "========================\n\n";
    
    $userId = getInput('user_id');
    $telegramId = getInput('telegram_id');
    
    if (!$userId && !$telegramId) {
        echo "📝 استفاده:\n";
        echo "php " . basename(__FILE__) . " --user_id=1\n";
        echo "یا\n";
        echo "php " . basename(__FILE__) . " --telegram_id=81650417\n\n";
        exit(1);
    }
    
    // پیدا کردن کاربر
    $user = findUser($pdo, $userId, $telegramId);
    
    if (!$user) {
        showMessage("کاربر یافت نشد", true);
        exit(1);
    }
    
    echo "👤 کاربر پیدا شد:\n";
    echo "   آیدی: " . $user['id'] . "\n";
    echo "   آیدی تلگرام: " . $user['telegram_id'] . "\n";
    echo "   نام: " . $user['first_name'] . " " . ($user['last_name'] ?? '') . "\n";
    echo "   یوزرنیم: " . ($user['username'] ?? 'ندارد') . "\n\n";
    
    echo "⚠️  آیا مطمئن هستید که می‌خواهید این کاربر را به همراه تمام سوابقش حذف کنید؟ (y/n): ";
    $handle = fopen("php://stdin", "r");
    $confirm = trim(fgets($handle));
    
    if (strtolower($confirm) !== 'y') {
        echo "❌ عملیات لغو شد.\n";
        exit(0);
    }
    
    // حذف کاربر
    $result = deleteUserCompletely($pdo, $user['id']);
    
    if ($result['success']) {
        showMessage($result['message']);
    } else {
        showMessage($result['message'], true);
        exit(1);
    }
    
} else {
    // حالت وب
    $userId = getInput('user_id');
    $telegramId = getInput('telegram_id');
    $confirm = getInput('confirm');
    
    // اگر فرم تأیید ارسال شده
    if ($confirm && $userId) {
        $result = deleteUserCompletely($pdo, $userId);
        showMessage($result['message'], !$result['success']);
        if ($result['success']) {
            echo '<div style="text-align: center; margin-top: 20px;">
                    <a href="' . basename(__FILE__) . '" style="color: #666; text-decoration: none;">↩️ بازگشت</a>
                  </div>';
        }
        exit;
    }
    
    // اگر آیدی داده شده، آمار کاربر را نشان بده
    if ($userId || $telegramId) {
        $user = findUser($pdo, $userId, $telegramId);
        if ($user) {
            showUserStats($pdo, $user);
        } else {
            showMessage("کاربر یافت نشد", true);
            showForm();
        }
    } else {
        // نمایش فرم اولیه
        showForm();
    }
}