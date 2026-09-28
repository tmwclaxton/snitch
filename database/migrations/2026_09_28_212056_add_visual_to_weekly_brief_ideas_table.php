<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_brief_ideas', function (Blueprint $table) {
            $table->text('visual')->nullable()->after('hook');
        });
    }

    public function down(): void
    {
        Schema::table('weekly_brief_ideas', function (Blueprint $table) {
            $table->dropColumn('visual');
        });
    }
};
