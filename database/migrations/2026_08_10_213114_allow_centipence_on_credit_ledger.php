<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ledger amounts stay in GBP pence with hundredths of a penny (0.01p = £0.0001).
     * Existing tenths-of-a-penny balances need no conversion.
     */
    public function up(): void
    {
        Schema::table('credit_balances', function (Blueprint $table) {
            $table->decimal('balance_pence', 14, 2)->default(0)->change();
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->decimal('amount_pence', 14, 2)->change();
            $table->decimal('balance_after_pence', 14, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('credit_balances', function (Blueprint $table) {
            $table->decimal('balance_pence', 14, 1)->default(0)->change();
        });

        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->decimal('amount_pence', 14, 1)->change();
            $table->decimal('balance_after_pence', 14, 1)->change();
        });
    }
};
