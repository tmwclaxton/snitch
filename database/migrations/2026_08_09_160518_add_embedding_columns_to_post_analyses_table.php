<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('post_analyses', function (Blueprint $table) {
            $table->json('embedding')->nullable()->after('custom_tags');
            $table->string('embedding_model')->nullable()->after('embedding');
            $table->string('embedding_hash', 64)->nullable()->after('embedding_model');
        });
    }

    public function down(): void
    {
        Schema::table('post_analyses', function (Blueprint $table) {
            $table->dropColumn(['embedding', 'embedding_model', 'embedding_hash']);
        });
    }
};
