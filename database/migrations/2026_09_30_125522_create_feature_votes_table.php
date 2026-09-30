<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('suggestion_id')->constrained('feature_suggestions')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'suggestion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_votes');
    }
};
