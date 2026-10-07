<?php

namespace Tests\Feature\Brief;

use App\Enums\Platform;
use App\Enums\TrackedAccountKind;
use App\Models\Post;
use App\Models\TrackedAccount;
use App\Models\User;
use App\Models\WeeklyBrief;
use App\Models\WeeklyBriefIdea;
use App\Services\Brief\BorrowedCompetitorNameSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BorrowedCompetitorNameSanitizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_finds_names_only_in_competitor_captions(): void
    {
        $sanitizer = app(BorrowedCompetitorNameSanitizer::class);

        $names = $sanitizer->borrowedNames(
            ['Our meetup is open to everyone this week'],
            ['Meet Jessie - nervous, alone, now a regular at great.friendship'],
        );

        $this->assertSame(['Jessie'], $names);
        $this->assertSame(
            "A member's story: nervous, alone, now a regular",
            $sanitizer->rewriteText("Jessie's story: nervous, alone, now a regular", $names),
        );
    }

    public function test_it_allows_names_that_also_appear_in_own_captions(): void
    {
        $sanitizer = app(BorrowedCompetitorNameSanitizer::class);

        $names = $sanitizer->borrowedNames(
            ['Jessie joined our walk this Sunday'],
            ['Spotlight on Jessie from last night'],
        );

        $this->assertSame([], $names);
    }

    public function test_it_rewrites_stored_weekly_ideas_in_place(): void
    {
        $user = User::factory()->create();
        $own = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'letsgosocialuk',
            'is_own_account' => true,
            'kind' => TrackedAccountKind::Competitor,
        ]);
        $friendship = TrackedAccount::factory()->for($user)->create([
            'platform' => Platform::Instagram,
            'handle' => 'great.friendship',
            'is_own_account' => false,
            'kind' => TrackedAccountKind::Competitor,
        ]);

        Post::factory()->forAccount($own)->create([
            'caption' => 'Come to our social this week',
            'posted_at' => now()->subDay(),
        ]);
        Post::factory()->forAccount($friendship)->create([
            'caption' => 'Community spotlight: Jessie was nervous and alone, now a regular',
            'posted_at' => now()->subDay(),
        ]);

        $weekStart = CarbonImmutable::now('Europe/London')->startOfWeek(CarbonImmutable::MONDAY);
        $brief = WeeklyBrief::factory()->for($user)->create([
            'week_start' => $weekStart->toDateString(),
            'status' => 'ready',
        ]);
        $idea = $brief->ideas()->create([
            'position' => 1,
            'format' => 'Reel',
            'hook' => "Jessie's story: nervous, alone, now a regular",
            'caption_angle' => 'Tell Jessie\'s arc in 20 seconds',
            'cta' => 'Comment your take',
            'hashtags' => ['#community', '#london', '#social'],
            'recommended_day' => 'Tue',
            'recommended_hour' => 19,
            'inspired_by_post_ids' => [],
            'why' => 'Inspired by Jessie on @great.friendship',
        ]);
        $idea = WeeklyBriefIdea::query()->findOrFail($idea->id);

        $updated = app(BorrowedCompetitorNameSanitizer::class)->sanitizeStoredWeeklyIdeas($user, $weekStart);

        $this->assertSame(1, $updated);
        $idea->refresh();
        $this->assertSame("A member's story: nervous, alone, now a regular", $idea->hook);
        $this->assertStringContainsString("a member's arc", mb_strtolower($idea->caption_angle));
        $this->assertStringNotContainsString('Jessie', $idea->hook.$idea->caption_angle.$idea->why);
    }
}
