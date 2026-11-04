<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_book_notes', function (Blueprint $table) {
            $table->id();

            $table->string('title')->nullable();
            $table->longText('details');

            $table->date('note_date');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestamps();

            $table->index('note_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_book_notes');
    }
};
