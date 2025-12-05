<?php
// app/Models/User.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

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
        'mobile',
        'is_active' // 🔴 این فیلد را اضافه کردم
    ];

    protected $casts = [
        'is_profile_completed' => 'boolean',
        'is_active' => 'boolean' // 🔴 این خط را اضافه کردم
    ];

    public function getWallet()
    {
        return Wallet::firstOrCreate(['user_id' => $this->id]);
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

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

    // 🔴 **رابطه اشتراک - اصلاح شده**
    public function subscription()
    {
        return $this->hasOne(UserSubscription::class, 'user_id')
            ->where('status', 'active')
            ->where('expiry_date', '>', Carbon::now())
            ->latest();
    }

    // 🔴 **متد hasActiveSubscription - اصلاح شده**
    public function hasActiveSubscription()
{
    $subscription = $this->getActiveSubscription();
    return $subscription && $subscription->isActive();
}

    // 🔴 **متد getActiveSubscription - اصلاح شده (همین متد باعث خطا بود)**
  public function getActiveSubscription()
{
    // اطمینان از اینکه expiry_date به درستی خوانده می‌شود
    $subscription = UserSubscription::where('user_id', $this->id)
        ->where('status', 'active')
        ->where('expiry_date', '>', Carbon::now())
        ->orderBy('created_at', 'DESC')
        ->first();
    
    if ($subscription) {
        error_log("Subscription found, expiry_date: " . $subscription->expiry_date);
        error_log("Type of expiry_date: " . gettype($subscription->expiry_date));
        if ($subscription->expiry_date instanceof \Carbon\Carbon) {
            error_log("It's a Carbon object, value: " . $subscription->expiry_date->toDateTimeString());
        }
    }
    
    return $subscription;
}



    // 🔴 **متد ساده‌تر برای تست**
    public function activeSubscription()
    {
        return UserSubscription::where('user_id', $this->id)
            ->where('status', 'active')
            ->first();
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

    public function getProfilePhoto()
    {
        if ($this->telegram_photo_id) {
            return $this->telegram_photo_id;
        }

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
    
    // 🔴 **متد کمکی برای چک کردن دسترسی اشتراک**
    public function checkSubscriptionAccess($feature)
    {
        $subscription = $this->getActiveSubscription();
        
        if (!$subscription) {
            return [
                'allowed' => false,
                'message' => "❌ برای استفاده از این امکان، نیاز به اشتراک فعال دارید.\n💎 از منوی اصلی گزینه 'اشتراک من' را انتخاب کنید."
            ];
        }
        
        switch ($feature) {
            case 'request_contact':
                if (!$subscription->canRequestContact()) {
                    return [
                        'allowed' => false,
                        'message' => "❌ سهمیه درخواست تماس شما تمام شده!\n📊 برای مشاهده سهمیه باقی‌مانده، از منوی اصلی گزینه 'اشتراک من' را انتخاب کنید."
                    ];
                }
                break;
                
            case 'view_suggestion':
                if (!$subscription->canViewSuggestion()) {
                    return [
                        'allowed' => false,
                        'message' => "❌ سهمیه مشاهده پیشنهادات شما تمام شده!\n📊 برای مشاهده سهمیه باقی‌مانده، از منوی اصلی گزینه 'اشتراک من' را انتخاب کنید."
                    ];
                }
                break;
        }
        
        return ['allowed' => true, 'message' => ''];
    }
    
    // 🔴 **متد جدید: دریافت آمار اشتراک**
    public function getSubscriptionStats()
    {
        $subscription = $this->getActiveSubscription();
        
        if (!$subscription) {
            return [
                'has_subscription' => false,
                'plan_name' => null,
                'days_remaining' => 0,
                'expiry_date' => null
            ];
        }
        
        return [
            'has_subscription' => true,
            'plan_name' => $subscription->plan->name ?? 'نامشخص',
            'days_remaining' => $subscription->daysRemaining(),
            'expiry_date' => $subscription->expiry_date
        ];
    }
}