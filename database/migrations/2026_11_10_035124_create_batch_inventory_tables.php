<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('batch_number', 100)->nullable();
            $table->date('expiry_date')->nullable();

            $table->decimal('received_quantity', 18, 4)->default(0);
            $table->decimal('remaining_quantity', 18, 4)->default(0);

            $table->decimal('unit_cost_afn', 20, 6)->default(0);

            $table->string('source_type', 100)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(
                ['product_id', 'warehouse_id', 'expiry_date'],
                'product_batches_fefo_index'
            );

            $table->index(
                ['source_type', 'source_id'],
                'product_batches_source_index'
            );
        });

        Schema::create('sale_item_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_item_id')
                ->constrained('sale_items')
                ->cascadeOnDelete();

            $table->foreignId('product_batch_id')
                ->constrained('product_batches')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);

            $table->decimal('unit_cost_afn', 20, 6);
            $table->decimal('total_cost_afn', 20, 4);

            $table->timestamps();

            $table->index(
                ['sale_item_id', 'product_batch_id'],
                'sale_item_batches_lookup_index'
            );
        });

        Schema::create('inventory_movement_batches', function (Blueprint $table) {
            $table->id();

            $table->foreignId('inventory_movement_id')
                ->constrained('inventory_movements')
                ->cascadeOnDelete();

            $table->foreignId('product_batch_id')
                ->constrained('product_batches')
                ->restrictOnDelete();

            $table->enum('direction', ['in', 'out']);

            $table->decimal('quantity', 18, 4);

            $table->decimal('unit_cost_afn', 20, 6);
            $table->decimal('total_cost_afn', 20, 4);

            $table->timestamps();

            $table->index(
                ['product_batch_id', 'direction'],
                'movement_batches_lookup_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movement_batches');
        Schema::dropIfExists('sale_item_batches');
        Schema::dropIfExists('product_batches');
    }
};
