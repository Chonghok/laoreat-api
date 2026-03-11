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
        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->cascadeOnDelete();
            $table->integer('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'customer_id'], 'uq_product_reviews_product_customer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
    }
};
