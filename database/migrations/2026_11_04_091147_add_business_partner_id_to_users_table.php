<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('business_partner_id')
                ->nullable()
                ->after('id')
                ->constrained('business_partners')
                ->restrictOnDelete()
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['business_partner_id']);
            $table->dropUnique(['users_business_partner_id_unique']);
            $table->dropColumn('business_partner_id');
        });
    }
};