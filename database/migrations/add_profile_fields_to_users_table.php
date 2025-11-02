<?php
// database/migrations/2024_01_01_000000_add_profile_fields_to_users_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddProfileFieldsToUsersTable extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // اضافه کردن فیلدهای پروفایل
            $table->text('bio')->nullable()->after('state');
            $table->integer('height')->nullable()->after('bio');
            $table->integer('weight')->nullable()->after('height');
            $table->string('education')->nullable()->after('weight');
            $table->string('job')->nullable()->after('education');
            $table->string('income')->nullable()->after('job');
            $table->string('city')->nullable()->after('income');
            $table->integer('age')->nullable()->after('city');
            $table->string('gender')->nullable()->after('age');
            $table->string('marital_status')->nullable()->after('gender');
            $table->boolean('is_profile_completed')->default(false)->after('marital_status');
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'bio', 'height', 'weight', 'education', 
                'job', 'income', 'city', 'age', 'gender', 
                'marital_status', 'is_profile_completed'
            ]);
        });
    }
}