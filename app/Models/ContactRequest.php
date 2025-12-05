<?php
// app/Models/ContactRequest.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactRequest extends Model
{
    protected $fillable = [
        'requester_id',
        'requested_id',
        'status',
        'requester_viewed_profile',
        'requested_viewed_requester_profile'
    ];
    
    protected $casts = [
        'requester_viewed_profile' => 'boolean',
        'requested_viewed_requester_profile' => 'boolean'
    ];
    
    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }
    
    public function requested()
    {
        return $this->belongsTo(User::class, 'requested_id');
    }
    
    public function isPending()
    {
        return $this->status === 'pending';
    }
    
    public function isApproved()
    {
        return $this->status === 'approved';
    }
    
    public function isRejected()
    {
        return $this->status === 'rejected';
    }
    
    public function isWaitingForSubscription()
    {
        return $this->status === 'waiting_for_subscription';
    }
}