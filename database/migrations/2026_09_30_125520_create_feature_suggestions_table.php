<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_suggestions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('status', 32)->default('open');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        $now = now();

        DB::table('feature_suggestions')->insert([
            [
                'user_id' => null,
                'title' => 'Weekly email digest',
                'body' => 'A short Monday email with your top rival winners and three post ideas for the week.',
                'status' => 'planned',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => null,
                'title' => 'TikTok tracking',
                'body' => 'Track TikTok accounts alongside Instagram so rival Shorts show up in your feed and winners.',
                'status' => 'building',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => null,
                'title' => 'Alerts when a rival post goes viral',
                'body' => 'Ping me when a tracked competitor clears a high X times usual threshold.',
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'user_id' => null,
                'title' => 'Competitor suggestions',
                'body' => 'Suggest fresh rivals from my niche when my current set goes quiet.',
                'status' => 'shipped',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_suggestions');
    }
};
