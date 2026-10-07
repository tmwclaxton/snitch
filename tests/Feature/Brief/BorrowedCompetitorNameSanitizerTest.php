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

    public function test_real_friendship_caption_only_replaces_jessie(): void
    {
        $sanitizer = app(BorrowedCompetitorNameSanitizer::class);
        $caption = 'Community Spotlight: meet Jessie... she even brought a book';

        $names = $sanitizer->borrowedNames(
            ['Come hike with us then grab a pint'],
            [$caption],
        );

        $this->assertSame(['Jessie'], $names);

        $hook = 'We hiked 10 miles and then got a pint';
        $angle = "Show how Let's Go Social makes it easy to meet new people with zero pressure.";
        $headline = 'Post a first-timer testimonial Reel to break a 6-day gap and catch up with competitors posting 5-7 times a week.';

        $this->assertSame($hook, $sanitizer->rewriteText($hook, $names));
        $this->assertSame($angle, $sanitizer->rewriteText($angle, $names));
        $this->assertSame($headline, $sanitizer->rewriteText($headline, $names));
        $this->assertSame(
            "A member's story: nervous, alone, now a regular",
            $sanitizer->rewriteText("Jessie's story: nervous, alone, now a regular", $names),
        );
        $this->assertSame(
            'Film or compile a 15-30 second clip of a member - they were nervous and felt welcome',
            $sanitizer->rewriteText(
                'Film or compile a 15-30 second clip of Jessie - they were nervous and felt welcome',
                $names,
            ),
        );
    }

    public function test_it_does_not_harvest_ordinary_capitalised_words(): void
    {
        $sanitizer = app(BorrowedCompetitorNameSanitizer::class);

        $names = $sanitizer->borrowedNames(
            [],
            [
                'Then we hiked. How could we forget? Why would first-timers worry?',
                'Living Room Listens Reel from Day One',
                'What a great Meet and greet',
            ],
        );

        $this->assertSame([], $names);
        $this->assertSame(
            'We hiked 10 miles and then got a pint',
            $sanitizer->rewriteText('We hiked 10 miles and then got a pint', ['Then', 'How', 'Could', 'First', 'Living', 'Room', 'Listens', 'Day', 'What', 'Meet']),
        );
    }

    public function test_rewrite_is_case_sensitive_on_whole_words(): void
    {
        $sanitizer = app(BorrowedCompetitorNameSanitizer::class);

        $this->assertSame(
            'We hiked 10 miles and then got a pint',
            $sanitizer->rewriteText('We hiked 10 miles and then got a pint', ['Then']),
        );
        $this->assertSame(
            'A member joined tonight',
            $sanitizer->rewriteText('Jessie joined tonight', ['Jessie']),
        );
        $this->assertSame(
            'Reply under jessie tonight',
            $sanitizer->rewriteText('Reply under jessie tonight', ['Jessie']),
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
            'caption' => 'Community Spotlight: meet Jessie... she even brought a book',
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
