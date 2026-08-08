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
        Schema::create('snitch_daily_platform_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('platform');
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamps();

            $table->unique(['date', 'platform']);
            $table->index('platform');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('snitch_daily_platform_stats');
    }
};
