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
        Schema::table('post_analyses', function (Blueprint $table) {
            $table->json('custom_tags')->nullable()->after('topics');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_analyses', function (Blueprint $table) {
            $table->dropColumn('custom_tags');
        });
    }
};
