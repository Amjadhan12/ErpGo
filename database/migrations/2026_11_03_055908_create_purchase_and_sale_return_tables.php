
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Purchase Returns
        |--------------------------------------------------------------------------
        */

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();

            $table->string('return_number', 50)->unique();

            $table->foreignId('purchase_id')
                ->constrained('purchases')
                ->restrictOnDelete();

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

            $table->date('return_date');

            $table->decimal('exchange_rate_to_afn', 20, 8);

            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->decimal('grand_total_afn', 20, 4)->default(0);

            $table->string('status', 20)->default('draft');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['purchase_id', 'status']);
            $table->index(['business_partner_id', 'return_date']);
            $table->index(['warehouse_id', 'return_date']);
        });

        /*
        |--------------------------------------------------------------------------
        | Purchase Return Items
        |--------------------------------------------------------------------------
        */

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('purchase_return_id')
                ->constrained('purchase_returns')
                ->restrictOnDelete();

            $table->foreignId('purchase_item_id')
                ->constrained('purchase_items')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);

            $table->decimal('unit_cost', 20, 6);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);

            $table->decimal('unit_cost_afn', 20, 6);
            $table->decimal('line_total_afn', 20, 4)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['purchase_item_id', 'purchase_return_id']);
        });

        /*
        |--------------------------------------------------------------------------
        | Sale Returns
        |--------------------------------------------------------------------------
        */

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();

            $table->string('return_number', 50)->unique();

            $table->foreignId('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

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

            $table->date('return_date');

            $table->decimal('exchange_rate_to_afn', 20, 8);

            $table->decimal('subtotal', 20, 4)->default(0);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('grand_total', 20, 4)->default(0);
            $table->decimal('grand_total_afn', 20, 4)->default(0);

            $table->string('status', 20)->default('draft');

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['sale_id', 'status']);
            $table->index(['business_partner_id', 'return_date']);
            $table->index(['warehouse_id', 'return_date']);
        });

        /*
        |--------------------------------------------------------------------------
        | Sale Return Items
        |--------------------------------------------------------------------------
        */

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_return_id')
                ->constrained('sale_returns')
                ->restrictOnDelete();

            $table->foreignId('sale_item_id')
                ->constrained('sale_items')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained('products')
                ->restrictOnDelete();

            $table->decimal('quantity', 18, 4);

            $table->decimal('unit_price', 20, 4);
            $table->decimal('discount_amount', 20, 4)->default(0);
            $table->decimal('line_total', 20, 4)->default(0);

            $table->decimal('unit_price_afn', 20, 6);
            $table->decimal('line_total_afn', 20, 4)->default(0);

            // Preserve original sale cost for accurate inventory and profit reports.
            $table->decimal('unit_cost_afn', 20, 6)->default(0);
            $table->decimal('total_cost_afn', 20, 4)->default(0);

            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['sale_item_id', 'sale_return_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
