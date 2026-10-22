<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Own-account trackers must use kind=own so MCP/competitor reads do not
        // treat them as rivals. Heal both directions for legacy rows.
        DB::table('tracked_accounts')
            ->where('is_own_account', true)
            ->where('kind', '!=', 'own')
            ->update(['kind' => 'own']);

        DB::table('tracked_accounts')
            ->where('kind', 'own')
            ->where('is_own_account', false)
            ->update(['is_own_account' => true]);
    }

    public function down(): void
    {
        DB::table('tracked_accounts')
            ->where('kind', 'own')
            ->update(['kind' => 'competitor']);
    }
};
