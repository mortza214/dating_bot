<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    protected $table = 'referrals';
    
    protected $fillable = [
        'referrer_id',
        'referred_id',
        'invite_code',
        'has_purchased',
        'bonus_amount',
        'bonus_paid_at'
    ];

    protected $dates = [
        'bonus_paid_at',
        'created_at',
        'updated_at'
    ];

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id');
    }

    public static function createReferral($referrerId, $referredId, $inviteCode)
    {
        return self::create([
            'referrer_id' => $referrerId,
            'referred_id' => $referredId,
            'invite_code' => $inviteCode,
            'has_purchased' => false,
            'bonus_amount' => 0
        ]);
    }

    public static function markAsPurchased($referredId, $bonusAmount)
    {
        $referral = self::where('referred_id', $referredId)->first();
        if ($referral) {
            $referral->update([
                'has_purchased' => true,
                'bonus_amount' => $bonusAmount,
                'bonus_paid_at' => now()
            ]);
            return true;
        }
        return false;
    }

    public static function getUserReferralStats($userId)
    {
        $totalReferrals = self::where('referrer_id', $userId)->count();
        $purchasedReferrals = self::where('referrer_id', $userId)
            ->where('has_purchased', true)
            ->count();
        $totalBonus = self::where('referrer_id', $userId)
            ->where('has_purchased', true)
            ->sum('bonus_amount');

        return [
            'total_referrals' => $totalReferrals,
            'purchased_referrals' => $purchasedReferrals,
            'pending_referrals' => $totalReferrals - $purchasedReferrals,
            'total_bonus' => $totalBonus
        ];
    }
}