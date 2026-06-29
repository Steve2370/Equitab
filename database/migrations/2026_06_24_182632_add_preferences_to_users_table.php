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
            $table->string('username')->nullable()->unique();
            $table->boolean('notif_member_joined')->default(true);
            $table->boolean('notif_payment_received')->default(true);
            $table->boolean('notif_renewal_reminder')->default(true);
            $table->boolean('notif_payment_failed')->default(true);
            $table->string('locale', 5)->default('fr');
            $table->string('currency', 3)->default('CAD');
            $table->boolean('show_real_name')->default(true);
            $table->boolean('allow_direct_contact')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username', 'notif_member_joined', 'notif_payment_received',
                'notif_renewal_reminder', 'notif_payment_failed',
                'locale', 'currency', 'show_real_name', 'allow_direct_contact',
            ]);
        });
    }
};
