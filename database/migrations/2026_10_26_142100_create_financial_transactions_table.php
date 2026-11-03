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
        Schema::create('financial_transactions', function (Blueprint $table) {
$table->id();

$table->foreignId('financial_account_id')
    ->constrained('financial_accounts')
    ->restrictOnDelete();

$table->foreignId('related_account_id')
    ->nullable()
    ->constrained('financial_accounts')
    ->restrictOnDelete();

$table->foreignId('created_by')
    ->nullable()
    ->constrained('users')
    ->nullOnDelete();

$table->string('transaction_type', 30);
$table->string('direction', 10);

$table->decimal('amount', 20, 4);
$table->decimal('exchange_rate', 20, 8)->nullable();

$table->uuid('transaction_group')->nullable();

$table->string('reference_type', 50)->nullable();
$table->unsignedBigInteger('reference_id')->nullable();

$table->dateTime('transaction_date');
$table->text('description')->nullable();

$table->timestamps();

$table->index('transaction_type');
$table->index('direction');
$table->index('transaction_date');
$table->index(['reference_type', 'reference_id']);
$table->index('transaction_group');

});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_transactions');
    }
};
