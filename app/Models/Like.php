<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Like extends Model
{
    protected $fillable = ['liker_id', 'liked_id', 'viewed', 'mutual'];
    
    public $timestamps = false;
    
    // روابط - باید به id (کلید اصلی) اشاره کنند
    public function liker()
    {
        return $this->belongsTo(User::class, 'liker_id'); // به users.id اشاره می‌کند
    }
    
    public function liked()
    {
        return $this->belongsTo(User::class, 'liked_id'); // به users.id اشاره می‌کند
    }
    
    // 🔹 **متد 1: تعداد لایک‌های دریافتی (بر اساس id)**
    public static function getReceivedCount($userId)
    {
        return self::where('liked_id', $userId)->count();
    }
    
    // 🔹 **متد 2: تعداد لایک‌های متقابل (بر اساس id)**
    public static function getMutualCount($userId)
    {
        return self::where(function($query) use ($userId) {
            $query->where('liker_id', $userId)
                  ->orWhere('liked_id', $userId);
        })
        ->where('mutual', 1)
        ->count() / 2;
    }
    
    // 🔹 **متد 3: بررسی و علامت‌گذاری لایک متقابل (بر اساس id)**
    public static function checkAndMarkMutual($userAId, $userBId)
    {
        $likeAB = self::where('liker_id', $userAId)
            ->where('liked_id', $userBId)
            ->first();
        
        $likeBA = self::where('liker_id', $userBId)
            ->where('liked_id', $userAId)
            ->first();
        
        if ($likeAB && $likeBA) {
            $likeAB->update(['mutual' => 1]);
            $likeBA->update(['mutual' => 1]);
            return true;
        }
        
        return false;
    }
    
    // 🔹 **متد 4: بررسی آیا کاربر A کاربر B را لایک کرده (بر اساس id)**
    public static function hasLiked($likerId, $likedId)
    {
        return self::where('liker_id', $likerId)
            ->where('liked_id', $likedId)
            ->exists();
    }
    
    // 🔹 **متد 5: ذخیره لایک جدید (بر اساس id)**
    public static function addLike($likerId, $likedId)
    {
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
    
    // 🔹 **متد 6: دریافت آمار لایک‌ها (بر اساس id)**
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
    
    // 🔹 **متد 7: علامت‌گذاری لایک به عنوان دیده شده (بر اساس id)**
    public static function markAsViewed($likerId, $likedId)
    {
        return self::where('liker_id', $likerId)
            ->where('liked_id', $likedId)
            ->update(['viewed' => 1]);
    }
    
    // 🔹 **متد 8: دریافت لایک‌کنندگان (بر اساس id)**
    public static function getLikers($userId, $limit = 10)
    {
        return self::with('liker')
            ->where('liked_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }
}