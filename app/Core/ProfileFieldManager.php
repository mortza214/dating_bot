<?php
// app/Core/ProfileFieldManager.php
namespace App\Core;

class ProfileFieldManager
{
    /**
     * مدیریت آپلود عکس
     */
    public function handlePhotoUpload($user, $photo, $botToken, $isMain = false)
{
    echo "🎯 handlePhotoUpload CALLED - SIMPLE VERSION\n";
    echo "📸 File ID: " . ($photo['file_id'] ?? 'NOT FOUND') . "\n";
    
    try {
        // فقط برای تست - ذخیره ساده
        $fileName = 'photo_' . uniqid() . '.jpg';
        
        $pdo = $this->getPDO();
        if ($isMain) {
            $sql = "UPDATE users SET profile_photo = ? WHERE telegram_id = ?";
        } else {
            $sql = "UPDATE users SET profile_photos = ? WHERE telegram_id = ?";
        }
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute([$fileName, $user->telegram_id]);
        
        echo "💾 Database result: " . ($result ? "SUCCESS" : "FAILED") . "\n";
        return true;
        
    } catch (\Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n";
        return false;
    }
}
    
    /**
     * دانلود عکس از تلگرام
     */
    private function downloadTelegramPhoto($photo, $botToken, $telegramId)
{
    try {
        echo "📡 Getting file info from Telegram...\n";
        $file = $this->getFileFromTelegram($photo['file_id'], $botToken);
        
        if (!$file || !isset($file['file_path'])) {
            echo "❌ Could not get file path from Telegram\n";
            return false;
        }
        
        $fileUrl = "https://api.telegram.org/file/bot{$botToken}/{$file['file_path']}";
        echo "📥 Downloading from: " . $fileUrl . "\n";
        
        // دانلود عکس
        $fileContent = file_get_contents($fileUrl);
        if ($fileContent === false) {
            echo "❌ Could not download photo from Telegram\n";
            return false;
        }
        
        // تولید نام فایل
        $fileName = uniqid() . '_' . $telegramId . '.jpg';
        $storagePath = __DIR__ . '/../../storage/profile_photos/' . $fileName;
        
        // ایجاد پوشه اگر وجود ندارد
        $storageDir = dirname($storagePath);
        if (!file_exists($storageDir)) {
            mkdir($storageDir, 0755, true);
            echo "📁 Created directory: $storageDir\n";
        }
        
        // ذخیره عکس
        if (file_put_contents($storagePath, $fileContent) === false) {
            echo "❌ Could not save photo to storage\n";
            return false;
        }
        
        echo "✅ Photo saved to: $storagePath\n";
        return $fileName;
        
    } catch (\Exception $e) {
        echo "🔴 Exception in downloadTelegramPhoto: " . $e->getMessage() . "\n";
        return false;
    }
}
    
    /**
     * دریافت اطلاعات فایل از تلگرام
     */
    private function getFileFromTelegram($fileId, $botToken)
    {
        $url = "https://api.telegram.org/bot{$botToken}/getFile?file_id={$fileId}";
        echo "🌐 Calling Telegram API: $url\n";
        
        $response = file_get_contents($url);
        if ($response === false) {
            echo "❌ Failed to call Telegram API\n";
            return false;
        }
        
        $data = json_decode($response, true);
        if (!$data || !$data['ok']) {
            echo "❌ Telegram API error: " . ($data['description'] ?? 'Unknown error') . "\n";
            return false;
        }
        
        echo "✅ Got file info from Telegram\n";
        return $data['result'];
    }
    
    /**
     * ذخیره اطلاعات عکس در دیتابیس
     */
    private function savePhotoToDatabase($telegramId, $photoPath, $isMain)
{
    try {
        $pdo = $this->getPDO();
        
        if ($isMain) {
            // ذخیره به عنوان عکس اصلی
            $sql = "UPDATE users SET profile_photo = ? WHERE telegram_id = ?";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([$photoPath, $telegramId]);
            echo "💾 Saved as main photo: " . ($result ? "SUCCESS" : "FAILED") . "\n";
            return $result;
        } else {
            // اضافه کردن به لیست عکس‌های اضافی
            $sql = "SELECT profile_photos FROM users WHERE telegram_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$telegramId]);
            $userData = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            $photos = [];
            if ($userData && $userData['profile_photos']) {
                // اگر داده JSON است، decode کن
                $decoded = json_decode($userData['profile_photos'], true);
                if (is_array($decoded)) {
                    $photos = $decoded;
                } else {
                    // اگر رشته ساده است
                    $photos = [$userData['profile_photos']];
                }
            }
            
            // اضافه کردن عکس جدید
            $photos[] = $photoPath;
            
            // تبدیل به JSON
            $photosJson = json_encode($photos, JSON_UNESCAPED_UNICODE);
            
            $sql = "UPDATE users SET profile_photos = ? WHERE telegram_id = ?";
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([$photosJson, $telegramId]);
            echo "💾 Saved as additional photo: " . ($result ? "SUCCESS" : "FAILED") . "\n";
            return $result;
        }
        
    } catch (\Exception $e) {
        echo "🔴 Exception in savePhotoToDatabase: " . $e->getMessage() . "\n";
        return false;
    }
}
    
    /**
     * اتصال به دیتابیس
     */
    private function getPDO()
    {
        static $pdo = null;
        if ($pdo === null) {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $dbname = $_ENV['DB_NAME'] ?? 'dating_system';
            $username = $_ENV['DB_USER'] ?? 'root';
            $password = $_ENV['DB_PASS'] ?? '';
            
            try {
                $pdo = new \PDO("mysql:host=$host;dbname=$dbname", $username, $password);
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
                echo "✅ Database connection established\n";
            } catch (\PDOException $e) {
                echo "❌ Database connection failed: " . $e->getMessage() . "\n";
                throw $e;
            }
        }
        return $pdo;
    }
}