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
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->text('competitor_brief')->nullable()->after('influencer_brief');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('brand_profiles', function (Blueprint $table) {
            $table->dropColumn('competitor_brief');
        });
    }
};
