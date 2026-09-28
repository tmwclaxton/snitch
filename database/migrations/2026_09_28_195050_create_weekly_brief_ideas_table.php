<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_brief_ideas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('weekly_brief_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('format');
            $table->string('hook');
            $table->text('caption_angle');
            $table->string('cta')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('recommended_day')->nullable();
            $table->unsignedTinyInteger('recommended_hour')->nullable();
            $table->json('inspired_by_post_ids')->nullable();
            $table->text('why')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->unique(['weekly_brief_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_brief_ideas');
    }
};
