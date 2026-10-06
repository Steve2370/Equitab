<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_customer_attempts', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained();
            $table->uuid('idempotency_key')->unique();
            $table->text('parameters');
            $table->timestamp('started_at');
        });
        Schema::create('subscription_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('group_id')->constrained();
            $table->foreignId('user_id')->constrained();
            $table->text('parameters');
            $table->string('price_id')->nullable();
            $table->string('subscription_id')->nullable()->unique();
            $table->timestamp('started_at');
            $table->unique(['group_id', 'user_id']);
        });
        Schema::create('payment_refund_attempts', function (Blueprint $table) {
            $table->foreignId('payment_id')->primary()->constrained();
            $table->uuid('idempotency_key')->unique();
            $table->string('refund_id')->nullable()->unique();
            $table->string('status')->default('requested');
            $table->text('reason');
            $table->timestamp('started_at');
            $table->timestamp('notified_at')->nullable();
        });
        Schema::table('group_members', function (Blueprint $table) {
            $table->timestamp('cancellation_requested_at')->nullable()->index();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('stripe_invoice_id')->nullable()->unique();
            $table->timestamp('confirmation_notified_at')->nullable();
            $table->timestamp('credentials_check_due_at')->nullable();
            $table->timestamp('credentials_check_queued_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['stripe_invoice_id']);
            $table->dropColumn(['stripe_invoice_id', 'confirmation_notified_at', 'credentials_check_due_at', 'credentials_check_queued_at']);
        });
        Schema::table('group_members', function (Blueprint $table) {
            $table->dropIndex(['cancellation_requested_at']);
            $table->dropColumn('cancellation_requested_at');
        });
        Schema::dropIfExists('payment_refund_attempts');
        Schema::dropIfExists('subscription_attempts');
        Schema::dropIfExists('billing_customer_attempts');
    }
};
