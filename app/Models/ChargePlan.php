<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChargePlan extends Model
{
    protected $table = 'charge_plans'; // جدول جدید یا همان subscription_plans با تغییر نام
    
    protected $fillable = [
        'name', 'description', 'amount', 'is_active'
    ];
    
    protected $casts = [
        'amount' => 'integer',
        'is_active' => 'boolean'
    ];
    
    public static function getActivePlans()
    {
        return self::where('is_active', true)->orderBy('amount')->get();
    }
    
    public static function getPlan($id)
    {
        return self::find($id);
    }
}