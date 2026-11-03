<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_id')
                ->constrained('purchases')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);

            // Original unit price in the purchase currency.
            $table->decimal('unit_cost', 20, 6);

            // Discount on this purchase line, in original currency.
            $table->decimal('discount_amount', 20, 4)->default(0);

            // Original line total in purchase currency.
            $table->decimal('line_total', 20, 4)->default(0);

            // Unit cost converted to AFN using the recorded exchange rate.
            $table->decimal('unit_cost_afn', 20, 6);

            // Line total converted to AFN.
            $table->decimal('line_total_afn', 20, 4)->default(0);

            $table->text('notes')->nullable();

          
            $table->index(['purchase_id', 'product_id']);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
    }
};

