<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('balance_pence')->default(0);
            $table->timestamps();

            $table->unique('user_id');
        });

        Schema::create('credit_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 64);
            $table->string('vendor', 32);
            $table->decimal('cogs_usd', 12, 6)->nullable();
            $table->decimal('multiplier', 6, 3)->nullable();
            $table->integer('amount_pence');
            $table->unsignedBigInteger('balance_after_pence');
            $table->json('meta')->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['user_id', 'vendor', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger_entries');
        Schema::dropIfExists('credit_balances');
    }
};
