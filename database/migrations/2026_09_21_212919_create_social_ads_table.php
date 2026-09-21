<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('social_account_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 32);
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url', 1024);
            $table->string('thumbnail_url', 1024)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_seen_at');
            $table->json('raw')->nullable();
            $table->timestamps();

            $table->unique(['social_account_id', 'url']);
            $table->index(['social_account_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_ads');
    }
};
