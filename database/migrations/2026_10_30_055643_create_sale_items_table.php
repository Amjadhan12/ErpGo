<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_id')
                ->constrained('sales')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);

            // Price in the original sale currency.
            $table->decimal('unit_price', 20, 4);

            $table->decimal('discount_amount', 20, 4)->default(0);

            $table->decimal('line_total', 20, 4);

            // Selling price converted to AFN.
            $table->decimal('unit_price_afn', 20, 6);

            $table->decimal('line_total_afn', 20, 4);

            // Cost of goods sold, based on weighted average inventory cost.
            $table->decimal('unit_cost_afn', 20, 6)->default(0);

            $table->decimal('total_cost_afn', 20, 4)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['sale_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};


