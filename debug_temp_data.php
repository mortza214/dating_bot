<?php
// debug_temp_data.php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;

try {
    // پیدا کردن کاربری که در حال افزودن فیلد هست
    $user = User::where('state', 'like', 'admin_adding_%')->first();
    
    if ($user) {
        echo "🔍 کاربر در حال افزودن فیلد:\n";
        echo "👤 کاربر: {$user->first_name} (ID: {$user->id})\n";
        echo "📝 state: {$user->state}\n";
        echo "💾 temp_data: " . ($user->temp_data ?: '❌ خالی') . "\n";
        
        if ($user->temp_data) {
            $tempData = json_decode($user->temp_data, true);
            echo "📋 محتوای temp_data:\n";
            print_r($tempData);
        }
    } else {
        echo "❌ هیچ کاربری در حال افزودن فیلد نیست\n";
    }
    
} catch (Exception $e) {
    echo "❌ خطا: " . $e->getMessage() . "\n";
}