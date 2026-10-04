<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('daily_brief_enabled')->default(false)->after('referral_code_id');
            $table->boolean('daily_brief_email')->default(false)->after('daily_brief_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['daily_brief_enabled', 'daily_brief_email']);
        });
    }
};
