<?php

namespace Tests\Unit;

use App\Services\Blog\BlogArticleGenerationService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BlogArticleGenerationServiceTest extends TestCase
{
    #[Test]
    public function it_normalises_article_plans_with_defaults(): void
    {
        $plan = BlogArticleGenerationService::normalizeArticlePlan([
            'title' => 'How to track rival Reels',
            'excerpt' => '',
            'tags' => ['Instagram', 'hooks'],
            'sources' => [['title' => 'Snitch', 'url' => 'https://www.snitchsocial.net']],
            'tldr' => [],
            'faq' => [
                ['question' => 'Is this public data?', 'answer' => 'Yes - public posts only.'],
            ],
            'cta' => '',
            'sections' => [
                ['heading' => 'Start with accounts', 'beats' => 'Pick rivals worth watching.'],
            ],
        ], 'fallback topic', 3);

        $this->assertSame('How to track rival Reels', $plan['title']);
        $this->assertNotSame('', $plan['excerpt']);
        $this->assertSame(['instagram', 'hooks'], $plan['tags']);
        $this->assertCount(3, $plan['tldr']);
        $this->assertCount(1, $plan['faq']);
        $this->assertStringContainsString('snitchsocial.net', $plan['cta']);
        $this->assertCount(3, $plan['sections']);
        $this->assertSame('Start with accounts', $plan['sections'][0]['heading']);
    }

    #[Test]
    public function it_strips_duplicate_lead_titles_from_body(): void
    {
        $body = "# How to track rival Reels\n\n## Next\n\nBody copy.";
        $normalized = BlogArticleGenerationService::normalizeBlogBodyForDisplay(
            $body,
            'How to track rival Reels',
        );

        $this->assertStringNotContainsString('# How to track rival Reels', $normalized);
        $this->assertStringContainsString('## Next', $normalized);
    }

    #[Test]
    public function generation_prompts_describe_instagram_only_product(): void
    {
        $service = file_get_contents(app_path('Services/Blog/BlogArticleGenerationService.php'));
        $config = file_get_contents(config_path('blog.php'));

        $this->assertNotFalse($service);
        $this->assertNotFalse($config);
        $this->assertStringContainsString('Instagram only', $service);
        $this->assertStringContainsString('Stay Instagram-focused', $service);
        $this->assertStringNotContainsString(
            'across TikTok, Instagram, YouTube, Facebook, and LinkedIn',
            $service,
        );
        $this->assertStringNotContainsString("'id' => 'tiktok-hooks'", $config);
        $this->assertStringNotContainsString("'id' => 'youtube-shorts'", $config);
        $this->assertStringNotContainsString("'id' => 'cross-platform'", $config);
        $this->assertStringContainsString("'id' => 'instagram-reels'", $config);
        $this->assertStringContainsString("'id' => 'instagram-gap'", $config);
    }
}
