<?php

namespace Tests\Feature;

use App\Enums\BlogStatus;
use App\Models\Blog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PublishBlogDraftsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishes_oldest_drafts(): void
    {
        $draft = Blog::factory()->draft()->create([
            'slug' => 'draft-to-publish',
            'title' => 'Draft to publish',
        ]);

        $exit = Artisan::call('blog:publish', ['--limit' => 5]);

        $this->assertSame(0, $exit);
        $draft->refresh();
        $this->assertSame(BlogStatus::Published, $draft->status);
        $this->assertNotNull($draft->published_at);
    }

    public function test_dry_run_lists_without_publishing(): void
    {
        Blog::factory()->draft()->create(['slug' => 'still-draft']);

        $exit = Artisan::call('blog:publish', ['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(
            BlogStatus::Draft,
            Blog::query()->where('slug', 'still-draft')->firstOrFail()->status,
        );
    }
}
