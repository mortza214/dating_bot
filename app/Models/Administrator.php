<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Administrator extends Model
{
    protected $table = 'administrators';
    
    protected $fillable = [
        'telegram_id', 'username', 'first_name', 'last_name'
    ];
    
    // متدهای موجود
    public static function isAdmin($telegramId)
    {
        return self::where('telegram_id', $telegramId)->exists();
    }
    
    // متد جدید: دریافت همه ادمین‌ها
    public static function getAllAdmins()
    {
        return self::all();
    }
    
    // متد جدید: دریافت ادمین‌ها با آیدی تلگرام
    public static function getAdminsTelegramIds()
    {
        return self::pluck('telegram_id')->toArray();
    }
    
    // متد جدید: اضافه کردن ادمین جدید
    public static function addAdmin($telegramId, $username = null, $firstName = null, $lastName = null)
    {
        return self::create([
            'telegram_id' => $telegramId,
            'username' => $username,
            'first_name' => $firstName,
            'last_name' => $lastName
        ]);
    }
}
?>