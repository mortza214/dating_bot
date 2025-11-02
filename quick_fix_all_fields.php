<?php
// quick_fix_all_fields.php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\ProfileField;

try {
    echo "🔧 تعمیر سریع تمام فیلدها...\n\n";
    
    // تمام فیلدهای فعال در profile_fields
    $activeFields = ProfileField::where('is_active', true)->get();
    $userInstance = new User();
    
    echo "📋 فیلدهای فعال در profile_fields:\n";
    foreach ($activeFields as $field) {
        echo "- {$field->field_name} ({$field->field_label})\n";
    }
    
    echo "\n🔍 بررسی fillable...\n";
    $fillable = $userInstance->getFillable();
    $missingFields = [];
    
    foreach ($activeFields as $field) {
        if (!in_array($field->field_name, $fillable)) {
            $missingFields[] = $field->field_name;
            echo "❌ فیلد missing در fillable: {$field->field_name}\n";
        } else {
            echo "✅ فیلد موجود در fillable: {$field->field_name}\n";
        }
    }
    
    if (!empty($missingFields)) {
        echo "\n⚠️ برخی فیلدها در fillable نیستند. لطفاً مدل User را بروزرسانی کنید.\n";
    } else {
        echo "\n🎉 همه فیلدها در fillable وجود دارند!\n";
    }
    
} catch (Exception $e) {
    echo "❌ خطا: " . $e->getMessage() . "\n";
}