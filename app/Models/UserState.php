<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserState extends Model
{
    protected $table = 'user_states';
    protected $fillable = ['user_id', 'current_state', 'state_data'];

    protected $casts = [
        'state_data' => 'array'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}