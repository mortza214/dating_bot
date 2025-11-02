<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $table = 'system_settings';
    
    protected $fillable = [
        'setting_key', 'setting_value', 'description'
    ];
    
    public static function getValue($key, $default = null)
    {
        $setting = self::where('setting_key', $key)->first();
        return $setting ? $setting->setting_value : $default;
    }
    
    public static function setValue($key, $value, $description = null)
    {
        $setting = self::where('setting_key', $key)->first();
        
        if ($setting) {
            $setting->update(['setting_value' => $value]);
        } else {
            self::create([
                'setting_key' => $key,
                'setting_value' => $value,
                'description' => $description
            ]);
        }
    }
}
?>