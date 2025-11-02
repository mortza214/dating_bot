<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class ChargeCode extends Model
{
    // public $timestamps = false; // ❌ این خط رو حذف کن
    
    protected $table = 'charge_codes';
    protected $fillable = ['code', 'amount', 'is_used', 'used_by', 'used_at', 'expires_at'];

    // دسترسی به created_at و updated_at به عنوان Carbon object
    public function getCreatedAtAttribute($value)
    {
        return $value ? Carbon::parse($value) : null;
    }

    public function getUpdatedAtAttribute($value)
    {
        return $value ? Carbon::parse($value) : null;
    }

    public function getUsedAtAttribute($value)
    {
        return $value ? Carbon::parse($value) : null;
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'used_by');
    }

    public function isValid()
    {
        return !$this->is_used && (!$this->expires_at || $this->expires_at > Carbon::now());
    }
}