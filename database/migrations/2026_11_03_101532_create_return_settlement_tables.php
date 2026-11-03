<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_number', 50)->unique();

            $table->foreignId('purchase_return_id')
                ->constrained('purchase_returns')
                ->restrictOnDelete();

            $table->foreignId('financial_account_id')
                ->constrained('financial_accounts')
                ->restrictOnDelete();

            $table->foreignId('currency_id')
                ->constrained('currencies')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->decimal('amount', 20, 4);
            $table->decimal('exchange_rate_to_afn', 20, 8);
            $table->date('settlement_date');
            $table->text('notes')->nullable();

            $table->timestamps();

           $table->index(
    ['purchase_return_id', 'settlement_date'],
    'prs_return_date_idx'
);
        });

        Schema::create('sale_return_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('settlement_number', 50)->unique();

            $table->foreignId('sale_return_id')
                ->constrained('sale_returns')
                ->restrictOnDelete();

            $table->foreignId('financial_account_id')
                ->constrained('financial_accounts')
                ->restrictOnDelete();

            $table->foreignId('currency_id')
                ->constrained('currencies')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->decimal('amount', 20, 4);
            $table->decimal('exchange_rate_to_afn', 20, 8);
            $table->date('settlement_date');
            $table->text('notes')->nullable();

            $table->timestamps();

           $table->index(
    ['sale_return_id', 'settlement_date'],
    'srs_return_date_idx'
);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_settlements');
        Schema::dropIfExists('purchase_return_settlements');
    }
};
