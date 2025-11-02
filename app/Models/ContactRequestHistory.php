<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ContactRequestHistory extends Model
{
    protected $table = 'contact_request_history';
    
    protected $fillable = [
        'user_id', 
        'requested_user_id', 
        'amount_paid',
        'requested_at'
    ];
    
    public $timestamps = false;
    
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
    
    public function requestedUser()
    {
        return $this->belongsTo(User::class, 'requested_user_id');
    }
    
    public static function addToHistory($userId, $requestedUserId, $amount)
    {
        return self::create([
            'user_id' => $userId,
            'requested_user_id' => $requestedUserId,
            'amount_paid' => $amount,
            'requested_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    public static function getUserHistory($userId)
    {
        return self::where('user_id', $userId)
            ->with('requestedUser')
            ->orderBy('requested_at', 'DESC')
            ->get();
    }
    
    public static function hasRequestedBefore($userId, $requestedUserId)
    {
        return self::where('user_id', $userId)
            ->where('requested_user_id', $requestedUserId)
            ->exists();
    }
}