<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('username', 100);
            $table->string('email', 255)->unique();
            $table->string('password', 255);
            $table->string('phone_number', 30)->unique();
            $table->timestamp('phone_verified_at')->nullable();
            $table->text('profile_url')->nullable();
            $table->string('profile_public_id', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
