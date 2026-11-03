<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('business_partner_id')
                ->constrained('business_partners')
                ->restrictOnDelete();

            $table->foreignId('currency_id')
                ->constrained('currencies')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Examples: opening_balance, purchase, sale, payment, receipt, return.
            $table->string('transaction_type', 30);

            // debit = partner owes company; credit = company owes partner.
            $table->string('direction', 10);

            $table->decimal('amount', 20, 4);

            // Optional link to the originating record.
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->dateTime('transaction_date');

            $table->string('description', 500)->nullable();

            $table->timestamps();

            $table->index([
                'business_partner_id',
                'currency_id',
                'transaction_date',
            ], 'partner_transactions_balance_index');

            $table->index(
                ['reference_type', 'reference_id'],
                'partner_transactions_reference_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_transactions');
    }
};