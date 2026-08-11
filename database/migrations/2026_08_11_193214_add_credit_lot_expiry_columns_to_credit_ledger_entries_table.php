<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('idempotency_key');
            $table->decimal('remaining_pence', 14, 2)->nullable()->after('expires_at');
            $table->index(['user_id', 'expires_at', 'remaining_pence'], 'credit_ledger_lots_idx');
        });

        // Existing positive lots keep remaining = amount; starter never expires (null).
        DB::table('credit_ledger_entries')
            ->where('amount_pence', '>', 0)
            ->whereNull('remaining_pence')
            ->update([
                'remaining_pence' => DB::raw('amount_pence'),
            ]);
    }

    public function down(): void
    {
        Schema::table('credit_ledger_entries', function (Blueprint $table) {
            $table->dropIndex('credit_ledger_lots_idx');
            $table->dropColumn(['expires_at', 'remaining_pence']);
        });
    }
};
