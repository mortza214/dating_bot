<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentRequest extends Model
{
    protected $table = 'payment_requests';
    
    protected $fillable = [
        'user_id', 'plan_id', 'amount', 'status', 'admin_id', 'transaction_date'
    ];
    
    const STATUS_PENDING = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';
    
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function plan()
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }
    
    public function admin()
    {
        return $this->belongsTo(Administrator::class, 'admin_id');
    }
    
    public static function getPendingRequests()
    {
        return self::with(['user', 'plan'])
                  ->where('status', self::STATUS_PENDING)
                  ->orderBy('created_at', 'desc')
                  ->get();
    }
    
    public static function createRequest($userId, $planId, $amount)
    {
        return self::create([
            'user_id' => $userId,
            'plan_id' => $planId,
            'amount' => $amount,
            'status' => self::STATUS_PENDING
        ]);
    }
    
    public function approve($adminId)
    {
        $this->update([
            'status' => self::STATUS_APPROVED,
            'admin_id' => $adminId,
            'transaction_date' => now()
        ]);
        
        // شارژ کیف پول کاربر
        $wallet = $this->user->getWallet();
        $wallet->charge($this->amount, "شارژ با اشتراک: {$this->plan->name}");
    }
    
    public function reject($adminId)
    {
        $this->update([
            'status' => self::STATUS_REJECTED,
            'admin_id' => $adminId
        ]);
    }
}
?>