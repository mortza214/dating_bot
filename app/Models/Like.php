<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    protected $fillable = ['liker_id', 'liked_id', 'viewed', 'mutual'];
    
    public $timestamps = false;
    
    // روابط
    public function liker()
    {
        return $this->belongsTo(User::class, 'liker_id');
    }
    
    public function liked()
    {
        return $this->belongsTo(User::class, 'liked_id');
    }
    
    // 🔹 **متد 1: تعداد لایک‌های دریافتی**
    public static function getReceivedCount($telegramId)
{
    return self::where('liked_id', $telegramId)->count();
}

public static function getMutualCount($telegramId)
{
    return self::where(function($query) use ($telegramId) {
        $query->where('liker_id', $telegramId)
              ->orWhere('liked_id', $telegramId);
    })
    ->where('mutual', 1)
    ->count() / 2;
}
    
   
    // 🔹 **متد 3: بررسی و علامت‌گذاری لایک متقابل**
   public static function checkAndMarkMutual($userATelegramId, $userBTelegramId)
{
    $likeAB = self::where('liker_id', $userATelegramId)
        ->where('liked_id', $userBTelegramId)
        ->first();
    
    $likeBA = self::where('liker_id', $userBTelegramId)
        ->where('liked_id', $userATelegramId)
        ->first();
    
    if ($likeAB && $likeBA) {
        $likeAB->update(['mutual' => 1]);
        $likeBA->update(['mutual' => 1]);
        return true;
    }
    
    return false;
}

    
    // 🔹 **متد 4: بررسی آیا کاربر A کاربر B را لایک کرده**
   public static function hasLiked($likerTelegramId, $likedTelegramId)
{
    return self::where('liker_id', $likerTelegramId)
        ->where('liked_id', $likedTelegramId)
        ->exists();
}
    // 🔹 **متد 5: ذخیره لایک جدید**
    public static function addLike($likerId, $likedId)
    {
        // بررسی تکراری نبودن لایک
        if (self::hasLiked($likerId, $likedId)) {
            return false;
        }
        
        return self::create([
            'liker_id' => $likerId,
            'liked_id' => $likedId,
            'viewed' => 0,
            'mutual' => 0
        ]);
    }
    
    // 🔹 **متد 6: دریافت آمار لایک‌ها**
    public static function getStats($userId)
    {
        return [
            'received' => self::getReceivedCount($userId),
            'mutual' => self::getMutualCount($userId),
            'given' => self::where('liker_id', $userId)->count(),
            'unviewed' => self::where('liked_id', $userId)
                ->where('viewed', 0)
                ->count()
        ];
    }
    
    // 🔹 **متد 7: علامت‌گذاری لایک به عنوان دیده شده**
 // در App\Models\Like.php
public static function markAsViewed($likerId, $likedId)
{
    return self::where('liker_id', $likerId)
        ->where('liked_id', $likedId)
        ->update(['viewed' => 1]);
}
    
    // 🔹 **متد 8: دریافت لایک‌کنندگان**
    public static function getLikers($userId, $limit = 10)
    {
        return self::with('liker')
            ->where('liked_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }
}