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
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            
            $table->string('name', 255);
            $table->decimal('price', 10, 2)->default(0.00);
            $table->string('unit_label', 100);
            $table->text('image_url');
            $table->string('image_public_id', 255);
            $table->text('description');

            $table->boolean('is_available')->default(true);
            $table->boolean('is_active')->default(true);

            $table->boolean('discount_active')->default(false);
            $table->decimal('discount_percent', 5, 2)->nullable();

            $table->timestamps();

            $table->index(['category_id']); 
            $table->index(['is_active', 'is_available']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
