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
        Schema::table('users', function (Blueprint $table) {
            // users.status ('active'|'suspended'|'banned') existe déjà mais
            // rien ne permet de borner une suspension dans le temps — un
            // admin ne peut donc pas "suspendre 7 jours", seulement
            // suspendre indéfiniment. suspended_until comble ce manque ;
            // NULL = suspension indéfinie (ou compte non suspendu).
            $table->timestamp('suspended_until')->nullable()->after('status');
            $table->text('suspension_reason')->nullable()->after('suspended_until');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['suspended_until', 'suspension_reason']);
        });
    }
};
