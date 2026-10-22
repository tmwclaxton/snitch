<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32); // brief | chat
            $table->string('status', 32)->default('queued');
            $table->string('cursor_run_id')->nullable();
            $table->string('cursor_background_composer_id')->nullable();
            $table->unsignedBigInteger('personal_access_token_id')->nullable()->index();
            $table->json('abilities')->nullable();
            $table->json('request_meta')->nullable();
            $table->json('result_meta')->nullable();
            $table->text('cost_notes')->nullable();
            $table->text('error')->nullable();
            $table->text('user_message')->nullable();
            $table->json('conversation')->nullable();
            $table->longText('reply_partial')->nullable();
            $table->longText('reply_final')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();

            $table->index(['type', 'status']);
            $table->index(['user_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
    }
};
