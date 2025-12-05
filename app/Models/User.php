<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $fillable = [
        'telegram_id',
        'username',
        'first_name',
        'last_name',
        'state',
        'is_profile_completed',
        // فیلدهای پروفایل موجود:
        'bio',
        'height',
        'weight',
        'education',
        'job',
        'income_level',
        'city',
        'age',
        'gender',
        'marital_status',
        'religion',
        'smoking',
        'children',
        'relationship_goal',
          'telegram_photo_id',
        // فیلدهای جدید نام و نام خانوادگی:
        'first_name_display',
        'health_status',
        'mobile'


    ];

    protected $casts = [
        'is_profile_completed' => 'boolean'
    ];

    public function getWallet()
    {
        return Wallet::firstOrCreate(['user_id' => $this->id]);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    // 🔴 اضافه کردن روابط جدید به مدل User موجود
    public function suggestions()
    {
        return $this->hasMany(UserSuggestion::class);
    }

    public function receivedSuggestions()
    {
        return $this->hasMany(UserSuggestion::class, 'suggested_user_id');
    }

    public function filters()
    {
        return $this->hasOne(UserFilter::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    // 🔴 اضافه کردن متدهای کمکی جدید
    public function hasActiveSubscription()
    {
        return $this->subscription && $this->subscription->isValid();
    }

    public function hasCustomFilters()
    {
        $filters = \App\Models\UserFilter::getFilters($this->id);
        return !empty($filters);
    }

    public function getCustomFilters()
    {
        return $this->filters ? $this->filters->filters : [];
    }
    public function referrals()
    {
        return $this->hasMany(Referral::class, 'referrer_id');
    }

    public function referredBy()
    {
        return $this->belongsTo(User::class, 'referred_by');
    }

    public function generateInviteCode()
    {
        do {
            $code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        } while (self::where('invite_code', $code)->exists());

        $this->update(['invite_code' => $code]);
        return $code;
    }

    public function getInviteLink()
    {
        if (!$this->invite_code) {
            $this->generateInviteCode();
        }

        $botUsername = $_ENV['TELEGRAM_BOT_USERNAME'] ?? 'dating_system_bot';
        return "https://t.me/{$botUsername}?start=ref_{$this->invite_code}";
    }

    public static function findByInviteCode($code)
    {
        return self::where('invite_code', $code)->first();
    }

    // در کلاس User (App\Models\User) متدهای زیر را اضافه کنید:

    public function getProfilePhoto()
    {
        if ($this->telegram_photo_id) {
            return $this->telegram_photo_id;
        }

        // اگر عکس پروفایل تلگرام وجود ندارد، از آواتار پیشفرض استفاده کنید
        return null;
    }

    public function updateTelegramPhoto($photoId)
    {
        $this->update([
            'telegram_photo_id' => $photoId,
            'has_custom_photo' => false
        ]);
    }

    public function hasTelegramPhoto()
    {
        return !empty($this->telegram_photo_id);
    }
    public function deductFromWallet($amount, $description = 'کسر اعتبار')
{
    try {
        \Illuminate\Support\Facades\DB::transaction(function () use ($amount, $description) {
            // دریافت کیف پول با قفل برای جلوگیری از race condition
            $wallet = \App\Models\Wallet::where('user_id', $this->id)->lockForUpdate()->first();
            
            if (!$wallet) {
                throw new \Exception('کیف پول یافت نشد');
            }

            if ($wallet->balance < $amount) {
                throw new \Exception('موجودی کافی نیست');
            }

            // کسر از کیف پول
            $wallet->balance -= $amount;
            $wallet->save();

            // ثبت تراکنش
            \App\Models\Transaction::create([
                'user_id' => $this->id,
                'amount' => -$amount,
                'type' => 'deduction',
                'description' => $description
            ]);
        });

        return true;
    } catch (\Exception $e) {
        throw new \Exception('خطا در کسر از کیف پول: ' . $e->getMessage());
    }
}
// در کلاس User (app/Models/User.php)
// public function deactivate($reason = 'موقت')
// {
//     try {
//         $pdo = self::getPDO();
//         $sql = "UPDATE users SET is_active = 0, deactivation_reason = ?, deactivated_at = NOW() WHERE telegram_id = ?";
//         $stmt = $pdo->prepare($sql);
//         return $stmt->execute([$reason, $this->telegram_id]);
//     } catch (\Exception $e) {
//         error_log("Error deactivating user: " . $e->getMessage());
//         return false;
//     }
// }

// public function activate()
// {
//     try {
//         $pdo = self::getPDO();
//         $sql = "UPDATE users SET is_active = 1, deactivation_reason = NULL, deactivated_at = NULL WHERE telegram_id = ?";
//         $stmt = $pdo->prepare($sql);
//         return $stmt->execute([$this->telegram_id]);
//     } catch (\Exception $e) {
//         error_log("Error activating user: " . $e->getMessage());
//         return false;
//     }
// }

public function isActive()
{
    return (bool) $this->is_active;
}

public function getStatusInfo()
{
    if ($this->is_active) {
        return "🟢 حساب شما فعال است";
    } else {
        $reason = $this->deactivation_reason ?? 'موقت';
        $date = $this->deactivated_at ? date('Y-m-d H:i', strtotime($this->deactivated_at)) : 'نامشخص';
        return "🔴 حساب شما غیرفعال است\n📅 از تاریخ: $date\n📝 دلیل: $reason";
    }
}
public function likesGiven()
{
    return $this->hasMany(Like::class, 'liker_id');
}

public function likesReceived()
{
    return $this->hasMany(Like::class, 'liked_id');
}

}