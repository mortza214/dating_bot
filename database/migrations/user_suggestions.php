<?php
// database/migrations/2025_01_28_000001_create_user_suggestions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateUserSuggestionsTable extends Migration
{
    public function up()
    {
        Schema::create('user_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('suggested_user_id')->constrained('users')->onDelete('cascade');
            $table->integer('shown_count')->default(0);
            $table->timestamp('last_shown_at')->nullable();
            $table->boolean('contact_requested')->default(false);
            $table->timestamps();
            
            $table->unique(['user_id', 'suggested_user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('user_suggestions');
    }
}