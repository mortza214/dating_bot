<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Transaction extends Model
{
    // public $timestamps = false; // ❌ این خط رو حذف کن
    
    protected $table = 'transactions';
    protected $fillable = ['user_id', 'type', 'amount', 'description', 'status', 'related_id'];

    // دسترسی به created_at به عنوان Carbon object
    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}