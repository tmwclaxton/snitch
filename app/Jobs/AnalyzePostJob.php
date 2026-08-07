<?php

namespace App\Jobs;

use App\Enums\PostType;
use App\Models\Post;
use App\Services\Analysis\VideoAnalysisService;
use App\Services\Winners\WinnerScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class AnalyzePostJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(public int $postId) {}

    public function handle(VideoAnalysisService $analysis, WinnerScorer $scorer): void
    {
        $post = Post::query()->with('analysis')->find($this->postId);

        if ($post === null || blank($post->media_url)) {
            return;
        }

        if (! in_array($post->type, [PostType::Reel, PostType::Video, PostType::Image, PostType::Carousel], true)) {
            return;
        }

        try {
            $analysis->analyzePost($post);
            $scorer->scoreAndPersist($post->fresh('analysis'));
        } catch (Throwable $e) {
            Log::warning('AnalyzePostJob failed', [
                'post_id' => $this->postId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
