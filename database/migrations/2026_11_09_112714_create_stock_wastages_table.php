<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_wastages', function (Blueprint $table) {
            $table->id();

            $table->string('wastage_number', 50)->unique();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->date('wastage_date');

            $table->string('status', 20)->default('posted');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['warehouse_id', 'wastage_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_wastages');
    }
};