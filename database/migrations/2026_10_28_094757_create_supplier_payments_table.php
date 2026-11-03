<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();

            $table->string('payment_number', 50)->unique();

            $table->foreignId('business_partner_id')
                ->constrained('business_partners')
                ->restrictOnDelete();

            $table->foreignId('financial_account_id')
                ->constrained('financial_accounts')
                ->restrictOnDelete();

            $table->foreignId('currency_id')
                ->constrained('currencies')
                ->restrictOnDelete();

            $table->decimal('amount', 20, 4);

            $table->decimal('exchange_rate_to_afn', 20, 8)
                ->default(1);

            $table->date('payment_date');

            $table->text('notes')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index([
                'business_partner_id',
                'payment_date',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
    }
};