<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_wastage_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_wastage_id')
                ->constrained('stock_wastages')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);

            $table->decimal('unit_cost_afn', 20, 6)->default(0);

            $table->decimal('total_cost_afn', 20, 6)->default(0);

            $table->string('reason', 100);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->unique(['stock_wastage_id', 'product_id']);

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_wastage_items');
    }
};