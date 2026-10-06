<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_connect_attempts', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->text('parameters');
            $table->timestamp('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_connect_attempts');
    }
};
