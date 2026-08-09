<?php

namespace Tests\Feature;

use App\Enums\BlogStatus;
use App\Models\Blog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BlogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_published_posts(): void
    {
        $published = Blog::factory()->published()->create([
            'title' => 'Track competitor TikToks',
            'slug' => 'track-competitor-tiktoks',
        ]);
        Blog::factory()->draft()->create([
            'slug' => 'secret-draft',
        ]);

        $this->get(route('blog.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('blog/Index')
                ->has('posts.data', 1)
                ->where('posts.data.0.slug', $published->slug)
                ->where('seo.indexable', true)
                ->where('seo.title', 'Blog')
            );
    }

    public function test_show_renders_published_post_markdown_as_html(): void
    {
        $blog = Blog::factory()->published()->create([
            'title' => 'Hook patterns that win',
            'slug' => 'hook-patterns-that-win',
            'excerpt' => 'How to spot remake-worthy hooks.',
            'body' => "## First section\n\nUseful advice about **hooks**.",
            'tags' => ['hooks', 'tiktok'],
        ]);

        $this->get(route('blog.show', $blog))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('blog/Show')
                ->where('post.slug', 'hook-patterns-that-win')
                ->where('seo.title', 'Hook patterns that win')
                ->where('seo.description', 'How to spot remake-worthy hooks.')
                ->where('seo.indexable', true)
                ->where('post.body_html', fn (string $html): bool => str_contains($html, 'hooks'))
            );

        $this->assertSame(1, $blog->fresh()->view_count);
    }

    public function test_show_returns_404_for_draft_and_future_posts(): void
    {
        $draft = Blog::factory()->draft()->create(['slug' => 'draft-post']);
        $future = Blog::factory()->create([
            'slug' => 'future-post',
            'status' => BlogStatus::Published,
            'published_at' => now()->addDay(),
        ]);

        $this->get(route('blog.show', $draft))->assertNotFound();
        $this->get(route('blog.show', $future))->assertNotFound();
    }

    public function test_sitemap_includes_published_blog_posts(): void
    {
        $published = Blog::factory()->published()->create(['slug' => 'sitemap-post']);
        Blog::factory()->draft()->create(['slug' => 'draft-not-in-sitemap']);

        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertSee(route('blog.index', absolute: true), false);
        $response->assertSee(route('blog.show', $published, absolute: true), false);
        $response->assertDontSee('draft-not-in-sitemap', false);
    }

    public function test_blog_index_html_includes_seo_description(): void
    {
        $description = config('seo.pages.blog.index.description');

        $this->get(route('blog.index'))
            ->assertOk()
            ->assertSee(e($description), false);
    }

    public function test_public_nav_and_footer_link_to_blog(): void
    {
        $nav = file_get_contents(resource_path('js/components/marketing/PublicNav.vue'));
        $footer = file_get_contents(resource_path('js/components/marketing/PublicFooter.vue'));

        $this->assertNotFalse($nav);
        $this->assertNotFalse($footer);
        $this->assertStringContainsString("label: 'Blog'", $nav);
        $this->assertStringContainsString("label: 'Blog'", $footer);
        $this->assertStringContainsString("from '@/routes/blog'", $nav);
        $this->assertStringContainsString("from '@/routes/blog'", $footer);
    }
}
