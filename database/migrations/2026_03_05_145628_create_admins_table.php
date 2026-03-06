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
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('username', 100);
            $table->string('email', 255);
            $table->string('password', 255);
            $table->string('role', 50);
            $table->string('profile_url', 255)->nullable();
            $table->string('profile_public_id', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('email');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
