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
            $table->string('stripe_connect_account_id', 255)->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // La migration d'origine (add_stripe_fields_to_users_table) créait
        // déjà la colonne avec string() sans longueur explicite, donc déjà
        // 255 par défaut chez Laravel — cette migration ne change en
        // pratique rien, il n'y a donc rien de différent à restaurer ici.
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_connect_account_id', 255)->nullable()->change();
        });
    }
};
