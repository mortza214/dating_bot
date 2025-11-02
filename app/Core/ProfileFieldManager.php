<?php
// app/Core/ProfileFieldManager.php

class ProfileFieldManager
{
    public function handlePhotoUpload($user, $photo, $botToken, $isMain = false)
    {
        try {
            echo "📸 Starting photo upload process...\n";
            
            // دانلود عکس از تلگرام
            $photoPath = $this->downloadTelegramPhoto($photo, $botToken);
            
            if ($photoPath) {
                echo "✅ Photo downloaded: $photoPath\n";
                
                // ذخیره اطلاعات در دیتابیس
                $pdo = $this->getPDO();
                
                if ($isMain) {
                    $sql = "UPDATE users SET profile_photo = ? WHERE telegram_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $result = $stmt->execute([$photoPath, $user->telegram_id]);
                    echo "📁 Main photo saved to database: " . ($result ? "YES" : "NO") . "\n";
                } else {
                    // گرفتن عکس‌های فعلی
                    $sql = "SELECT profile_photos FROM users WHERE telegram_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$user->telegram_id]);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $photos = [];
                    if ($result && $result['profile_photos']) {
                        $photos = json_decode($result['profile_photos'], true);
                        if (!is_array($photos)) {
                            $photos = [];
                        }
                    }
                    
                    $photos[] = $photoPath;
                    
                    $sql = "UPDATE users SET profile_photos = ? WHERE telegram_id = ?";
                    $stmt = $pdo->prepare($sql);
                    $result = $stmt->execute([json_encode($photos), $user->telegram_id]);
                    echo "📁 Additional photo saved to database: " . ($result ? "YES" : "NO") . "\n";
                }
                
                return true;
            } else {
                echo "❌ Photo download failed\n";
                return false;
            }
            
        } catch (Exception $e) {
            echo "❌ Photo upload error: " . $e->getMessage() . "\n";
            error_log("Photo upload error: " . $e->getMessage());
            return false;
        }
    }
    
    private function downloadTelegramPhoto($photo, $botToken)
    {
        try {
            echo "🔗 Getting file info from Telegram...\n";
            
            // گرفتن file_path از تلگرام
            $file = $this->getFileFromTelegram($photo['file_id'], $botToken);
            
            if (!$file || !isset($file['file_path'])) {
                throw new Exception("Could not get file path from Telegram");
            }

            $fileUrl = "https://api.telegram.org/file/bot{$botToken}/{$file['file_path']}";
            echo "📥 Downloading from: $fileUrl\n";
            
            // دانلود عکس
            $fileContent = file_get_contents($fileUrl);
            if ($fileContent === false) {
                throw new Exception("Could not download photo from Telegram");
            }

            // تولید نام فایل
            $fileName = uniqid() . '.jpg';
            $storagePath = __DIR__ . '/../../storage/profile_photos/' . $fileName;

            // ایجاد پوشه اگر وجود ندارد
            $storageDir = dirname($storagePath);
            if (!file_exists($storageDir)) {
                mkdir($storageDir, 0755, true);
                echo "📁 Created directory: $storageDir\n";
            }

            // ذخیره عکس
            if (file_put_contents($storagePath, $fileContent) === false) {
                throw new Exception("Could not save photo to storage");
            }

            echo "💾 Photo saved to: $storagePath\n";
            return $fileName;

        } catch (Exception $e) {
            echo "❌ Photo download error: " . $e->getMessage() . "\n";
            error_log("Photo download error: " . $e->getMessage());
            return false;
        }
    }
    
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
    
    private function getPDO()
    {
        static $pdo = null;
        if ($pdo === null) {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $dbname = $_ENV['DB_NAME'] ?? 'dating_system';
            $username = $_ENV['DB_USER'] ?? 'root';
            $password = $_ENV['DB_PASS'] ?? '';
            
            $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        }
        return $pdo;
    }
}