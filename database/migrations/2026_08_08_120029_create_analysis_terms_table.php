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
        Schema::create('analysis_terms', function (Blueprint $table) {
            $table->id();
            $table->string('dimension', 32);
            $table->string('slug', 80);
            $table->string('label', 120);
            $table->timestamps();

            $table->unique(['dimension', 'slug']);
            $table->index('dimension');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analysis_terms');
    }
};
