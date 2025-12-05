<?php
namespace App\Models;

class UserFilter
{
    protected $table = 'user_filters';
    
    public static function getFilters($userId)
    {
        $pdo = self::getPDO();
        
        // 🔴 تغییر اساسی: خواندن از یک رکورد واحد با فیلد JSON
        $sql = "SELECT filters FROM user_filters WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        
        if ($result && !empty($result->filters)) {
            // decode کردن JSON
            $decodedFilters = json_decode($result->filters, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($decodedFilters)) {
                error_log("✅ فیلترهای خوانده شده از دیتابیس: " . json_encode($decodedFilters));
                
                // اطمینان از وجود همه کلیدهای ضروری
                $defaultFilters = self::getDefaultFilters();
                foreach ($defaultFilters as $key => $defaultValue) {
                    if (!isset($decodedFilters[$key])) {
                        $decodedFilters[$key] = $defaultValue;
                    }
                }
                
                return $decodedFilters;
            }
        }
        
        error_log("⚠️ هیچ فیلتری برای کاربر {$userId} یافت نشد، استفاده از فیلترهای پیشفرض");
        return self::getDefaultFilters();
    }
    
    public static function saveFilters($userId, $filters)
{
    $pdo = self::getPDO();
    
    // 🔴 DECODE کردن همه مقادیر قبل از ذخیره در JSON
    $decodedFilters = [];
    foreach ($filters as $key => $value) {
        if (is_string($value)) {
            // اگر مقدار رشته است و encoded شده، decode کن
            $decodedValue = urldecode($value);
            $decodedFilters[$key] = $decodedValue;
            error_log("🔤 Decoding: {$value} -> {$decodedValue}");
        } elseif (is_array($value)) {
            // اگر مقدار آرایه است (مثل شهرها)، همه المان‌ها را decode کن
            $decodedFilters[$key] = array_map('urldecode', $value);
            error_log("🔤 Decoding array: " . json_encode($value) . " -> " . json_encode($decodedFilters[$key]));
        } else {
            $decodedFilters[$key] = $value;
        }
    }
    
    $filtersJson = json_encode($decodedFilters, JSON_UNESCAPED_UNICODE);
    error_log("💾 ذخیره فیلترهای DECODED برای کاربر {$userId}: " . $filtersJson);
    
    // بررسی وجود رکورد قبلی
    $checkSql = "SELECT id FROM user_filters WHERE user_id = ?";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([$userId]);
    $existing = $checkStmt->fetch(\PDO::FETCH_OBJ);
    
    if ($existing) {
        // آپدیت رکورد موجود
        $updateSql = "UPDATE user_filters SET filters = ?, updated_at = NOW() WHERE user_id = ?";
        $updateStmt = $pdo->prepare($updateSql);
        $result = $updateStmt->execute([$filtersJson, $userId]);
        error_log("🔵 آپدیت رکورد موجود: " . ($result ? "موفق" : "ناموفق"));
        return $result;
    } else {
        // درج رکورد جدید
        $insertSql = "INSERT INTO user_filters (user_id, filters, created_at, updated_at) VALUES (?, ?, NOW(), NOW())";
        $insertStmt = $pdo->prepare($insertSql);
        $result = $insertStmt->execute([$userId, $filtersJson]);
        error_log("🔵 درج رکورد جدید: " . ($result ? "موفق" : "ناموفق"));
        return $result;
    }
}
    
    public static function getDefaultFilters()
    {
        return [
            'gender' => '',
            'min_age' => '',
            'max_age' => '',
            'city' => []
        ];
    }
    
    public static function resetFilters($userId)
    {
        $pdo = self::getPDO();
        $sql = "DELETE FROM user_filters WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$userId]);
        error_log("🔄 بازنشانی فیلترهای کاربر {$userId}: " . ($result ? "موفق" : "ناموفق"));
        return $result;
    }
    
    public static function hasFilters($userId)
    {
        $pdo = self::getPDO();
        $sql = "SELECT COUNT(*) as count FROM user_filters WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        return $result->count > 0;
    }
    
    // 🔴 متد جدید: بررسی مستقیم مقدار یک فیلتر خاص
    public static function getFilterValue($userId, $fieldName)
    {
        $filters = self::getFilters($userId);
        return $filters[$fieldName] ?? null;
    }
    
    // 🔴 متد جدید: دیباگ کامل فیلترها
    public static function debugFilters($userId)
    {
        $pdo = self::getPDO();
        
        $sql = "SELECT * FROM user_filters WHERE user_id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$userId]);
        $result = $stmt->fetch(\PDO::FETCH_OBJ);
        
        $debugInfo = [
            'user_id' => $userId,
            'has_record' => !empty($result),
            'raw_data' => $result ? $result->filters : null,
            'parsed_filters' => self::getFilters($userId),
            'default_filters' => self::getDefaultFilters()
        ];
        
        return $debugInfo;
    }
    
    private static function getPDO()
    {
        $host = 'localhost';
        $dbname = 'dating_system';
        $username = 'root';
        $password = '';
        
        $pdo = new \PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        
        return $pdo;
    }
}