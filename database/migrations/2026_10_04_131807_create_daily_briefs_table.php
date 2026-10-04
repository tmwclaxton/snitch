<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('brief_date');
            $table->string('status')->default('generating');
            $table->text('headline')->nullable();
            $table->json('payload')->nullable();
            $table->json('facts')->nullable();
            $table->string('model')->nullable();
            $table->unsignedTinyInteger('llm_attempts')->default(0);
            $table->decimal('credits_charged_pence', 14, 2)->default(0);
            $table->boolean('was_free')->default(true);
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'brief_date']);
            $table->index(['user_id', 'brief_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_briefs');
    }
};
