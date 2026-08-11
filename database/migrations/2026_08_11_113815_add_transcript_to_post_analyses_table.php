<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_analyses', function (Blueprint $table) {
            $table->text('transcript')->nullable()->after('how_to_copy');
        });
    }

    public function down(): void
    {
        Schema::table('post_analyses', function (Blueprint $table) {
            $table->dropColumn('transcript');
        });
    }
};
