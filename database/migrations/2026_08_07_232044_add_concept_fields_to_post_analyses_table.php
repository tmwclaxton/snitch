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
            $table->text('how_to_copy')->nullable()->after('cta');
            $table->string('concept')->nullable()->after('how_to_copy');
            $table->json('topics')->nullable()->after('concept');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('post_analyses', function (Blueprint $table) {
            $table->dropColumn(['how_to_copy', 'concept', 'topics']);
        });
    }
};
