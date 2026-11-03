<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       
Schema::create('opening_balances', function (Blueprint $table) {
    $table->id();

    $table->foreignId('financial_account_id')
        ->constrained('financial_accounts')
        ->restrictOnDelete();

    $table->decimal('amount', 20, 4);

    $table->date('opening_date');

    $table->text('notes')->nullable();

    $table->foreignId('created_by')
        ->nullable()
        ->constrained('users')
        ->nullOnDelete();

    $table->timestamps();

    $table->unique(
        ['financial_account_id', 'opening_date'],
        'opening_balance_account_date_unique'
    );
});

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opening_balances');
    }
};
