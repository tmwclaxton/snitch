<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('id', 1)
            ->update(['daily_brief_enabled' => true]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('id', 1)
            ->update(['daily_brief_enabled' => false]);
    }
};
