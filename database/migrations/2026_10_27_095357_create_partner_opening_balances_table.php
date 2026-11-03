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
    Schema::create('partner_opening_balances', function (Blueprint $table) {
        $table->id();

        $table->foreignId('business_partner_id')
            ->constrained('business_partners')
            ->restrictOnDelete();

        $table->foreignId('currency_id')
            ->constrained('currencies')
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
            ['business_partner_id', 'currency_id', 'opening_date'],
            'partner_opening_balance_unique'
        );

        $table->index('opening_date');
    });
}

public function down(): void
{
    Schema::dropIfExists('partner_opening_balances');
}

};
