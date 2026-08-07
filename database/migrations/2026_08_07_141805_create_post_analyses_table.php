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
        Schema::create('post_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->text('hook')->nullable();
            $table->unsignedSmallInteger('hook_window_end_sec')->nullable();
            $table->text('visual_summary')->nullable();
            $table->text('idea')->nullable();
            $table->text('format_notes')->nullable();
            $table->json('sfx')->nullable();
            $table->json('music')->nullable();
            $table->text('cta')->nullable();
            $table->string('model')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('analyzed_at')->nullable();
            $table->timestamps();

            $table->unique('post_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_analyses');
    }
};
