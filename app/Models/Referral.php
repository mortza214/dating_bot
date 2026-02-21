<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    protected $table = 'referrals';
    protected $fillable = [
        'referrer_id', 'referred_id', 'invite_code', 
        'has_purchased', 'bonus_amount', 'status'
    ];

    public static function getUserReferralStats($userId)
    {
        $total = self::where('referrer_id', $userId)->count();
        $purchased = self::where('referrer_id', $userId)
            ->where('has_purchased', 1)
            ->count();
        $pending = $total - $purchased;
        
        $totalBonus = self::where('referrer_id', $userId)
            ->where('has_purchased', 1)
            ->sum('bonus_amount');
        
        return [
            'total_referrals' => $total,
            'purchased_referrals' => $purchased,
            'pending_referrals' => $pending,
            'total_bonus' => $totalBonus ?? 0
        ];
    }
    
    public static function getByReferredId($referredId)
    {
        return self::where('referred_id', $referredId)->first();
    }
}