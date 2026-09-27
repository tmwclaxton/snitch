<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_analyses', function (Blueprint $table) {
            $table->unsignedTinyInteger('analysis_attempt')->nullable()->after('error_message');
            $table->json('caption_echo_diagnostics')->nullable()->after('analysis_attempt');
        });
    }

    public function down(): void
    {
        Schema::table('post_analyses', function (Blueprint $table) {
            $table->dropColumn(['analysis_attempt', 'caption_echo_diagnostics']);
        });
    }
};
