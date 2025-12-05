<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class UserSubscription extends Model
{
    protected $fillable = [
        'user_id', 'plan_id', 'start_date', 'expiry_date', 'status',
        'remaining_daily_contacts', 'remaining_total_contacts',
        'remaining_daily_suggestions', 'remaining_total_suggestions',
        'used_daily_contacts', 'used_total_contacts',
        'used_daily_suggestions', 'used_total_suggestions',
        'last_reset_date'
    ];
    
    protected $dates = ['start_date', 'expiry_date', 'last_reset_date'];
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }
    
    public function isActive()
{
    // بررسی کنید expiry_date مقدار دارد
    if (!$this->expiry_date) {
        return false;
    }
    
    return $this->status === 'active' && 
           $this->expiry_date > Carbon::now();
}
    
    public function daysRemaining()
{
    if (!$this->isActive()) {
        return 0;
    }
    
    // مطمئن شوید expiry_date مقدار دارد
    if (!$this->expiry_date) {
        return 0;
    }
    
    return Carbon::now()->diffInDays($this->expiry_date, false);
}
    
    public function resetDailyLimits()
    {
        if (!$this->last_reset_date || 
            $this->last_reset_date->lt(Carbon::today())) {
            
            $plan = $this->plan;
            $this->update([
                'remaining_daily_contacts' => $plan->max_daily_contacts,
                'remaining_daily_suggestions' => $plan->max_daily_suggestions,
                'used_daily_contacts' => 0,
                'used_daily_suggestions' => 0,
                'last_reset_date' => Carbon::today()
            ]);
        }
    }
    
    public function canRequestContact()
    {
        if (!$this->isActive()) {
            return false;
        }
        
        $this->resetDailyLimits();
        
        return $this->remaining_daily_contacts > 0 && 
               $this->remaining_total_contacts > 0;
    }
    
    public function useContactRequest()
    {
        if (!$this->canRequestContact()) {
            return false;
        }
        
        $this->decrement('remaining_daily_contacts');
        $this->decrement('remaining_total_contacts');
        $this->increment('used_daily_contacts');
        $this->increment('used_total_contacts');
        
        return true;
    }
    
    public function canViewSuggestion()
    {
        if (!$this->isActive()) {
            return false;
        }
        
        $this->resetDailyLimits();
        
        return $this->remaining_daily_suggestions > 0 && 
               $this->remaining_total_suggestions > 0;
    }
    
    public function useSuggestionView()
    {
        if (!$this->canViewSuggestion()) {
            return false;
        }
        
        $this->decrement('remaining_daily_suggestions');
        $this->decrement('remaining_total_suggestions');
        $this->increment('used_daily_suggestions');
        $this->increment('used_total_suggestions');
        
        return true;
    }
    
    public function getUsageStats()
    {
        $plan = $this->plan;
        return [
            'daily_contacts' => [
                'used' => $this->used_daily_contacts,
                'remaining' => $this->remaining_daily_contacts,
                'total' => $plan->max_daily_contacts
            ],
            'total_contacts' => [
                'used' => $this->used_total_contacts,
                'remaining' => $this->remaining_total_contacts,
                'total' => $plan->total_contacts
            ],
            'daily_suggestions' => [
                'used' => $this->used_daily_suggestions,
                'remaining' => $this->remaining_daily_suggestions,
                'total' => $plan->max_daily_suggestions
            ],
            'total_suggestions' => [
                'used' => $this->used_total_suggestions,
                'remaining' => $this->remaining_total_suggestions,
                'total' => $plan->total_suggestions
            ]
        ];
    }
}