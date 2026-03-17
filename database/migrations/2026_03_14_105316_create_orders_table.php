<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('delivery_type_id')->constrained('delivery_types')->restrictOnDelete();
            $table->string('order_number', 30)->unique();
            $table->decimal('subtotal', 10, 2)->default(0.00);
            $table->string('promo_code', 50)->nullable();
            $table->decimal('promo_discount_percent', 5, 2)->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0.00);
            $table->decimal('delivery_fee', 10, 2)->default(0.00);
            $table->decimal('total_amount', 10, 2)->default(0.00);
            $table->string('status', 30)->default('accepted');
            $table->string('payment_method', 20);
            $table->string('contact_name', 255);
            $table->string('contact_phone', 50);
            $table->text('delivery_address')->nullable();
            $table->decimal('delivery_lat', 10, 7)->nullable();
            $table->decimal('delivery_lng', 10, 7)->nullable();
            $table->dateTime('scheduled_for')->nullable();
            $table->string('note_for_rider', 500)->nullable();
            $table->timestamps();
            
            $table->index('customer_id');
            $table->index('delivery_type_id');
            $table->index('status');
            $table->index('payment_method');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};