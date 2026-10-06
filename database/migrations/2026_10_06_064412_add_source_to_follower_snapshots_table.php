<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('follower_snapshots', function (Blueprint $table) {
            $table->string('source', 32)->nullable()->after('followers');
        });
    }

    public function down(): void
    {
        Schema::table('follower_snapshots', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
