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
    Schema::create('chat_messages', function (Blueprint $table) {
        $table->id();

        $table->foreignId('business_partner_id')
            ->constrained('business_partners')
            ->cascadeOnDelete();

        $table->foreignId('sender_id')
            ->constrained('users')
            ->restrictOnDelete();

        $table->foreignId('recipient_id')
            ->constrained('users')
            ->restrictOnDelete();

        $table->text('message');

        $table->timestamp('read_at')->nullable();

        $table->timestamps();

        $table->index(['business_partner_id', 'created_at']);
        $table->index(['recipient_id', 'read_at']);
    });
}

    /**
     * Reverse the migrations.
     */
   public function down(): void
{
    Schema::dropIfExists('chat_messages');
}
};
