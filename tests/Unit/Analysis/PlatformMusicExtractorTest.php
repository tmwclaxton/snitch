<?php

namespace Tests\Unit\Analysis;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Analysis\PlatformMusicExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformMusicExtractorTest extends TestCase
{
    use RefreshDatabase;

    public function test_extracts_tiktok_normalized_music(): void
    {
        $social = SocialAccount::factory()->create([
            'platform' => 'tiktok',
        ]);
        $post = Post::factory()->forSocialAccount($social)->create([
            'raw_payload' => [
                'normalized_music' => [
                    'musicName' => 'Late night feelings',
                    'musicAuthor' => 'Mylesxiety',
                    'musicOriginal' => false,
                    'musicId' => '7123',
                ],
            ],
        ]);

        $music = app(PlatformMusicExtractor::class)->fromPost($post);

        $this->assertSame('Late night feelings', $music['title']);
        $this->assertSame('Mylesxiety', $music['artist']);
        $this->assertFalse($music['is_original_audio']);
        $this->assertSame('7123', $music['platform_id']);
        $this->assertSame('platform', $music['source']);
    }

    public function test_extracts_instagram_song_shape(): void
    {
        $extracted = app(PlatformMusicExtractor::class)->fromArray([
            'song_name' => 'Dirt Cheap',
            'artist_name' => 'Cody Johnson',
            'uses_original_audio' => false,
            'audio_id' => '99',
        ]);

        $this->assertSame('Dirt Cheap', $extracted['title']);
        $this->assertSame('Cody Johnson', $extracted['artist']);
        $this->assertFalse($extracted['is_original_audio']);
    }

    public function test_merge_prefers_platform_over_model_guess(): void
    {
        $extractor = app(PlatformMusicExtractor::class);
        $platform = $extractor->fromArray([
            'musicName' => 'original sound',
            'musicAuthor' => 'Nike',
            'musicOriginal' => true,
            'musicId' => '1',
        ]);

        $merged = $extractor->mergeForAnalysis(
            $platform,
            'Invented Hit Song',
            'Fake Artist',
            false,
        );

        $this->assertSame('original sound', $merged['title']);
        $this->assertSame('Nike', $merged['artist']);
        $this->assertTrue($merged['is_original_audio']);
        $this->assertSame('platform', $merged['source']);
        $this->assertSame('1', $merged['platform_id']);
    }

    public function test_merge_falls_back_to_model_when_platform_missing(): void
    {
        $merged = app(PlatformMusicExtractor::class)->mergeForAnalysis(
            null,
            'Bed Track',
            'Studio Loaf',
            true,
        );

        $this->assertSame([
            'title' => 'Bed Track',
            'artist' => 'Studio Loaf',
            'is_original_audio' => true,
            'source' => 'model',
        ], $merged);
    }
}
