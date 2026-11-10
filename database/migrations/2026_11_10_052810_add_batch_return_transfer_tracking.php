
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_item_batches', function (Blueprint $table) {
            $table->decimal('returned_quantity', 18, 4)
                ->default(0)
                ->after('quantity');
        });

        Schema::create('sale_return_item_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_return_item_id')
                ->constrained('sale_return_items')
                ->cascadeOnDelete();

            $table->foreignId('sale_item_batch_id')
                ->constrained('sale_item_batches')
                ->restrictOnDelete();

            $table->foreignId('product_batch_id')
                ->constrained('product_batches')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost_afn', 20, 6);
            $table->decimal('total_cost_afn', 20, 4);

            $table->timestamps();

            $table->index(
                ['sale_return_item_id', 'sale_item_batch_id'],
                'sale_return_batches_lookup_index'
            );
        });

        Schema::create('stock_transfer_item_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('stock_transfer_item_id')
                ->constrained('stock_transfer_items')
                ->cascadeOnDelete();

            $table->foreignId('source_product_batch_id')
                ->constrained('product_batches')
                ->restrictOnDelete();

            $table->foreignId('destination_product_batch_id')
                ->constrained('product_batches')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost_afn', 20, 6);
            $table->decimal('total_cost_afn', 20, 4);

            $table->timestamps();

            $table->index(
                ['stock_transfer_item_id', 'source_product_batch_id'],
                'stock_transfer_batches_lookup_index'
            );

            $table->index(
                'destination_product_batch_id',
                'transfer_destination_batch_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_item_batches');
        Schema::dropIfExists('sale_return_item_batches');

        Schema::table('sale_item_batches', function (Blueprint $table) {
            $table->dropColumn('returned_quantity');
        });
    }
};
