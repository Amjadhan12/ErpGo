<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();

            $table->string('sale_number', 50)->unique();

            $table->foreignId('business_partner_id')
                ->constrained('business_partners')
                ->restrictOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('currency_id')
                ->constrained('currencies')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->date('sale_date');

            $table->string('invoice_number', 100)->nullable();

            // Exchange rate: how many AFN equal one unit of sale currency.
            // For AFN sales, the rate must be 1.
            $table->decimal('exchange_rate_to_afn', 20, 8);

            // Totals in the original sale currency.
            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);

            // Total expressed in AFN for accounting.
            $table->decimal('grand_total_afn', 20, 4)->default(0);

            $table->string('payment_status', 20)->default('unpaid');
            $table->string('status', 20)->default('draft');

            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_partner_id', 'sale_date']);
            $table->index(['warehouse_id', 'sale_date']);
            $table->index(['currency_id', 'sale_date']);
            $table->index(['status', 'payment_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales');
    }
};


