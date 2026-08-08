<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tracked_accounts', function (Blueprint $table) {
            // TikTok (and similar) CDN avatar URLs regularly exceed varchar(255).
            $table->text('avatar')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tracked_accounts', function (Blueprint $table) {
            $table->string('avatar')->nullable()->change();
        });
    }
};
