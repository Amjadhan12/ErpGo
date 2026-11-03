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
       Schema::create('financial_accounts', function (Blueprint $table) {
    $table->id();

    $table->foreignId('currency_id')
        ->constrained('currencies')
        ->restrictOnDelete();

    $table->string('name', 150);
    $table->string('account_type', 30);
    $table->string('holder_type', 30)->default('company');
    $table->string('location', 150)->nullable();
    $table->string('account_number', 100)->nullable();
    $table->text('notes')->nullable();

    $table->boolean('is_active')->default(true);

    $table->timestamps();
    $table->softDeletes();

    $table->index('account_type');
    $table->index('holder_type');
    $table->index('name');
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
