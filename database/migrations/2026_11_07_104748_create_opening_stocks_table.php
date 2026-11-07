
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_stocks', function (Blueprint $table) {
            $table->id();

            $table->string('opening_number', 50)->unique();

            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->date('opening_date');
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['warehouse_id', 'opening_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opening_stocks');
    }
};
