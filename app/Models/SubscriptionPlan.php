<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name', 'description', 'price', 'duration_days',
        'max_daily_contacts', 'total_contacts',
        'max_daily_suggestions', 'total_suggestions',
        'is_active'
    ];
    
    protected $casts = [
        'price' => 'float',
        'is_active' => 'boolean'
    ];
    
    // 🔴 **اصلاح: orderBy('price') نه orderBy('amount')**
    public static function getActivePlans()
    {
        return self::where('is_active', true)->orderBy('price', 'asc')->get();
    }
}
