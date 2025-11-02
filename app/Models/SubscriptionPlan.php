<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionPlan extends Model
{
    protected $table = 'subscription_plans';
    
    protected $fillable = [
        'name', 'duration_days', 'amount', 'description', 'is_active'
    ];
    
    public static function getActivePlans()
    {
        return self::where('is_active', true)->orderBy('amount')->get();
    }
    
    public static function getPlan($id)
    {
        return self::where('id', $id)->where('is_active', true)->first();
    }
}
?>