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
            $table->string('stripe_connect_account_id')->nullable()->unique();
            $table->enum('stripe_connect_status', [
                'not_started',
                'pending',
                'active',
                'restricted',
                'disabled',
            ])->default('not_started');

            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_identity_session_id')->nullable();
            $table->enum('identity_status', [
                'unverified', 'pending', 'verified', 'failed',
            ])->default('unverified');
            $table->timestamp('identity_verified_at')->nullable();
            $table->decimal('trust_score', 3, 2)->nullable();
            $table->integer('completed_payments_count')->default(0);
            $table->integer('disputed_payments_count')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'stripe_connect_account_id',
                'stripe_connect_status',
                'stripe_customer_id',
                'stripe_identity_session_id',
                'identity_status',
                'identity_verified_at',
                'trust_score',
                'completed_payments_count',
                'disputed_payments_count',
            ]);
        });
    }
};
