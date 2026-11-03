<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

            $table->string('payment_number', 50)->unique();

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

            $table->date('payment_date');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['sale_id', 'payment_date']);
            $table->index(['currency_id', 'payment_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_payments');
    }
};
