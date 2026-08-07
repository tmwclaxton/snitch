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
        Schema::create('winner_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('preset')->default('balanced');
            $table->unsignedTinyInteger('min_engagement_rate')->nullable();
            $table->unsignedInteger('min_views')->nullable();
            $table->unsignedInteger('min_likes')->nullable();
            $table->unsignedTinyInteger('recency_days')->nullable();
            $table->json('weights')->nullable();
            $table->json('advanced')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('winner_rules');
    }
};
