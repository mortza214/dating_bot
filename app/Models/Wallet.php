<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $table = 'wallets';
    protected $fillable = ['user_id', 'balance'];

    public $timestamps = false;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class, 'user_id', 'user_id');
    }

    public function charge($amount, $description = '', $type = 'charge')
    {
        $this->balance += $amount;
        $this->save();

        // ایجاد تراکنش بدون updated_at
        Transaction::create([
            'user_id' => $this->user_id,
            'type' => $type, // 🔴 استفاده از پارامتر type
            'amount' => $amount,
            'description' => $description,
            'status' => 'completed'
        ]);

        return $this;
    }

    public function deduct($amount, $description = "", $type = 'purchase')
    {
        $pdo = null;
        $maxRetries = 2;
        $retryCount = 0;

        while ($retryCount < $maxRetries) {
            try {
                // ایجاد اتصال PDO
                $pdo = $this->getPDO();

                // تست اتصال قبل از استفاده
                $pdo->query('SELECT 1')->fetch(\PDO::FETCH_ASSOC);

                // شروع تراکنش
                $pdo->beginTransaction();

                // کسر از کیف پول
                $sql = "UPDATE wallets SET balance = balance - ? WHERE user_id = ? AND balance >= ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([$amount, $this->user_id, $amount]);

                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    return false; // موجودی کافی نیست
                }

                // ثبت تراکنش با نوع مشخص
                $transactionSql = "INSERT INTO transactions (user_id, amount, type, description, created_at) 
                              VALUES (?, ?, ?, ?, NOW())";
                $transactionStmt = $pdo->prepare($transactionSql);
                $transactionStmt->execute([$this->user_id, -$amount, $type, $description]);

                $pdo->commit();

                // آپدیت موجودی در شیء فعلی
                $this->refresh(); // 🔴 رفرش کردن مدل از دیتابیس
                $this->balance -= $amount;
                $this->save();

                return true;

            } catch (\Exception $e) {
                if ($pdo) {
                    try {
                        $pdo->rollBack();
                    } catch (\Exception $rollbackEx) {
                        error_log("❌ خطا در rollback: " . $rollbackEx->getMessage());
                    }
                }

                // اگر خطا مربوط به قطعی MySQL باشد، مجدد تلاش کن
                if (strpos($e->getMessage(), 'MySQL server has gone away') !== false && $retryCount < $maxRetries) {
                    $retryCount++;
                    error_log("🔄 تلاش مجدد برای اتصال به دیتابیس ({$retryCount}/{$maxRetries})");
                    sleep(1); // کمی صبر کن
                    continue;
                }

                error_log("❌ خطا در کسر از کیف پول: " . $e->getMessage());
                return false;
            } finally {
                // بستن اتصال
                $pdo = null;
            }
        }

        return false;
    }


    private function getPDO()
    {
        $host = 'localhost';
        $dbname = 'dating_system';
        $username = 'root';
        $password = '';

        try {
            $pdo = new \PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(\PDO::ATTR_TIMEOUT, 30); // 🔴 تنظیم timeout
            $pdo->setAttribute(\PDO::ATTR_PERSISTENT, false); // 🔴 غیرفعال کردن persistent connection

            return $pdo;
        } catch (\PDOException $e) {
            error_log("❌ خطا در اتصال به دیتابیس: " . $e->getMessage());
            throw $e;
        }
    }

    public function hasEnoughBalance($amount)
    {
        return $this->balance >= $amount;
    }
}