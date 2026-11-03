<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_category_id')
                ->constrained('product_categories')
                ->restrictOnDelete();

            $table->string('sku')->nullable()->unique();
            $table->string('name');
            $table->string('product_type', 30)->default('goods');

            $table->string('unit', 30)->default('piece');
            $table->text('description')->nullable();

            $table->decimal('minimum_stock', 18, 4)->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};