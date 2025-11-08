<?php
namespace App\Core;

class DatabaseManager
{
    private static $lastCheckTime = 0;
    private static $checkInterval = 300; // هر 5 دقیقه چک کن

    public static function ensureConnection()
    {
        // چک کردن فقط هر چند دقیقه یکبار برای performance
        if (time() - self::$lastCheckTime < self::$checkInterval) {
            return true;
        }

        try {
            \Illuminate\Database\Capsule\Manager::connection()->getPdo();
            self::$lastCheckTime = time();
            return true;
        } catch (\Exception $e) {
            error_log("❌ Database connection lost: " . $e->getMessage());
            
            try {
                \Illuminate\Database\Capsule\Manager::connection()->reconnect();
                error_log("✅ Database reconnected successfully");
                self::$lastCheckTime = time();
                return true;
            } catch (\Exception $reconnectException) {
                error_log("❌ Failed to reconnect: " . $reconnectException->getMessage());
                return false;
            }
        }
    }

    public static function executeWithRetry(callable $callback, $maxRetries = 3)
    {
        $retries = 0;
        
        while ($retries < $maxRetries) {
            try {
                if (!self::ensureConnection()) {
                    throw new \Exception("No database connection");
                }
                
                return $callback();
            } catch (\Exception $e) {
                $retries++;
                error_log("❌ Database operation failed (attempt {$retries}/{$maxRetries}): " . $e->getMessage());
                
                if ($retries < $maxRetries) {
                    sleep(2); // وقفه قبل از تلاش مجدد
                    \Illuminate\Database\Capsule\Manager::connection()->reconnect();
                }
            }
        }
        
        throw new \Exception("Failed after {$maxRetries} attempts");
    }
}