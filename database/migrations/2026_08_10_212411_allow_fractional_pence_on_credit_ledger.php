<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger amounts stay in GBP pence, but allow tenths of a penny (e.g. 0.2p for NanoGPT).
     * Existing whole-pence balances need no conversion.
     */
    public function up(): void
    {
        Schema::table('credit_balances', function (Blueprint $table) {
            $table->decimal('balance_pence', 14, 1)->default(0)->change();
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->decimal('amount_pence', 14, 1)->change();
            $table->decimal('balance_after_pence', 14, 1)->change();
        });
    }

    public function down(): void
    {
        Schema::table('credit_balances', function (Blueprint $table) {
            $table->unsignedBigInteger('balance_pence')->default(0)->change();
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->integer('amount_pence')->change();
            $table->unsignedBigInteger('balance_after_pence')->change();
        });
    }
};
