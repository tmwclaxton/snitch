<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_accounts', function (Blueprint $table) {
            $table->string('kind', 32)->default('competitor')->after('platform');
            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('tracked_accounts', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
