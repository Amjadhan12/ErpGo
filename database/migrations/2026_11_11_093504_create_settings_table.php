<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title_pa', 190);
            $table->string('title_en', 190);
            $table->string('phone1', 20)->nullable();
            $table->string('phone2', 20)->nullable();
            $table->text('image_path')->nullable();
            $table->text('address')->nullable();
            $table->text('bill_text')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
