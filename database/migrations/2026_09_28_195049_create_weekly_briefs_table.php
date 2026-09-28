<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->string('status')->default('ready');
            $table->json('best_times')->nullable();
            $table->json('heat_grid')->nullable();
            $table->boolean('thin_data')->default(false);
            $table->decimal('credits_charged_pence', 14, 2)->default(0);
            $table->boolean('was_free')->default(false);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'week_start']);
            $table->index(['user_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_briefs');
    }
};
