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
        Schema::create('analysis_term_post_analysis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_analysis_id')->constrained('post_analyses')->cascadeOnDelete();
            $table->foreignId('analysis_term_id')->constrained('analysis_terms')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['post_analysis_id', 'analysis_term_id'], 'analysis_term_pivot_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_term_post_analysis');
    }
};
