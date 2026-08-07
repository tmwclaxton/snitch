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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tracked_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform');
            $table->string('type');
            $table->string('external_id')->nullable();
            $table->string('url');
            $table->timestamp('posted_at')->nullable();
            $table->text('caption')->nullable();
            $table->string('media_url')->nullable();
            $table->json('metrics')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['tracked_account_id', 'external_id']);
            $table->index(['user_id', 'platform', 'type']);
            $table->index(['user_id', 'posted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
