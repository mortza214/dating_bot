<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('profile_photo')->nullable()->after('is_profile_completed');
            $table->json('profile_photos')->nullable()->after('profile_photo');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['profile_photo', 'profile_photos']);
        });
    }
};