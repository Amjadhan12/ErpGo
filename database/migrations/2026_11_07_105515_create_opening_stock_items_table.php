
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_stock_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('opening_stock_id')
                ->constrained('opening_stocks')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost_afn', 20, 6)->default(0);
            $table->decimal('total_cost_afn', 20, 6)->default(0);

            $table->timestamps();

            $table->unique(['opening_stock_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_stock_items');
    }
};
