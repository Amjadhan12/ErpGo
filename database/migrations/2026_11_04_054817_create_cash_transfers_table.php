<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $table) {
            $table->id();

            $table->string('transfer_number', 50)->unique();

            $table->foreignId('from_account_id')
                ->constrained('financial_accounts')
                ->restrictOnDelete();

            $table->foreignId('to_account_id')
                ->constrained('financial_accounts')
                ->restrictOnDelete();

            $table->foreignId('currency_id')
                ->constrained('currencies')
                ->restrictOnDelete();

            $table->decimal('amount', 20, 4);

            $table->date('transfer_date');

            $table->text('description')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->softDeletes();

            $table->index(['from_account_id', 'transfer_date']);
            $table->index(['to_account_id', 'transfer_date']);
            $table->index(['currency_id', 'transfer_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_transfers');
    }
};
