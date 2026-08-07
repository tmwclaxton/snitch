<?php

namespace Tests\Feature;

use App\Jobs\AutofillBrandFromWebsiteJob;
use App\Models\User;
use App\Services\Onboarding\BrandWebsiteAutofillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OnboardingAutofillTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_start_autofill(): void
    {
        $this->postJson(route('onboarding.autofill'), [
            'website' => 'https://loaf.example',
        ])->assertUnauthorized();
    }

    public function test_user_can_start_autofill_job(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('onboarding.autofill'), [
                'website' => 'https://loaf.example',
            ])
            ->assertAccepted()
            ->assertJsonStructure(['id', 'status']);

        $autofillId = $response->json('id');

        $this->assertIsString($autofillId);
        Queue::assertPushed(AutofillBrandFromWebsiteJob::class, function (AutofillBrandFromWebsiteJob $job) use ($user, $autofillId): bool {
            return $job->userId === $user->id
                && $job->autofillId === $autofillId
                && $job->website === 'https://loaf.example';
        });

        $this->assertSame('pending', Cache::get(AutofillBrandFromWebsiteJob::cacheKeyFor($user->id, $autofillId))['status']);
    }

    public function test_autofill_requires_valid_website(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('onboarding.autofill'), [
                'website' => 'not-a-url',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['website']);

        Queue::assertNothingPushed();
    }

    public function test_autofill_normalizes_website_without_scheme(): void
    {
        Queue::fake();

        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson(route('onboarding.autofill'), [
                'website' => 'www.grantgunner.org',
            ])
            ->assertAccepted();

        $autofillId = $response->json('id');

        Queue::assertPushed(AutofillBrandFromWebsiteJob::class, function (AutofillBrandFromWebsiteJob $job) use ($user, $autofillId): bool {
            return $job->userId === $user->id
                && $job->autofillId === $autofillId
                && $job->website === 'https://www.grantgunner.org';
        });
    }

    public function test_autofill_status_returns_completed_fields(): void
    {
        $user = User::factory()->create();
        $autofillId = '11111111-1111-4111-8111-111111111111';

        Cache::put(AutofillBrandFromWebsiteJob::cacheKeyFor($user->id, $autofillId), [
            'status' => 'completed',
            'website' => 'https://loaf.example',
            'fields' => [
                'name' => 'Loaf Local',
                'website' => 'https://loaf.example',
                'description' => 'Neighborhood bakery',
                'own_handles' => [
                    'instagram' => '@loaf',
                    'tiktok' => null,
                    'facebook' => null,
                    'linkedin' => null,
                ],
            ],
            'error' => null,
        ], now()->addMinutes(15));

        $this->actingAs($user)
            ->getJson(route('onboarding.autofill.status', $autofillId))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('fields.name', 'Loaf Local')
            ->assertJsonPath('fields.own_handles.instagram', '@loaf');
    }

    public function test_autofill_job_scrapes_and_stores_fields(): void
    {
        config([
            'snitch.firecrawl.api_key' => 'test-key',
            'snitch.firecrawl.base_url' => 'https://api.firecrawl.test/v1',
            'snitch.nanogpt.api_key' => '',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://api.firecrawl.test/v1/scrape' => Http::response([
                'success' => true,
                'data' => [
                    'markdown' => "# Loaf Local\n\nWe bake sourdough for the neighborhood every morning with real butter and long ferments.",
                    'summary' => 'We bake sourdough for the neighborhood every morning with real butter and long ferments.',
                    'links' => [
                        'https://instagram.com/loaf.local',
                        'https://www.tiktok.com/@loaflocal',
                    ],
                    'metadata' => [
                        'title' => 'Loaf Local | Bakery',
                        'description' => 'Neighborhood bakery content brand',
                    ],
                ],
            ]),
        ]);

        $user = User::factory()->create();
        $autofillId = '22222222-2222-4222-8222-222222222222';

        Cache::put(AutofillBrandFromWebsiteJob::cacheKeyFor($user->id, $autofillId), [
            'status' => 'pending',
            'website' => 'https://loaf.example',
            'fields' => null,
            'error' => null,
        ], now()->addMinutes(15));

        (new AutofillBrandFromWebsiteJob($user->id, $autofillId, 'https://loaf.example'))
            ->handle(app(BrandWebsiteAutofillService::class));

        $payload = Cache::get(AutofillBrandFromWebsiteJob::cacheKeyFor($user->id, $autofillId));

        $this->assertSame('completed', $payload['status']);
        $this->assertSame('Loaf Local', $payload['fields']['name']);
        $this->assertSame(
            'We bake sourdough for the neighborhood every morning with real butter and long ferments.',
            $payload['fields']['description'],
        );
        $this->assertSame('@loaf.local', $payload['fields']['own_handles']['instagram']);
        $this->assertSame('@loaflocal', $payload['fields']['own_handles']['tiktok']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.firecrawl.test/v1/scrape'
                && $request['url'] === 'https://loaf.example'
                && ($request['onlyMainContent'] ?? null) === false
                && in_array('markdown', $request['formats'], true)
                && in_array('links', $request['formats'], true)
                && in_array('summary', $request['formats'], true);
        });
    }

    public function test_onboarding_page_puts_website_first_with_autofill_control(): void
    {
        $page = file_get_contents(resource_path('js/pages/onboarding/Index.vue'));
        $form = file_get_contents(resource_path('js/components/BrandProfileForm.vue'));

        $this->assertNotFalse($page);
        $this->assertNotFalse($form);
        $this->assertStringContainsString('BrandProfileForm', $page);
        $this->assertStringContainsString('Autofill from website', $form);
        $this->assertStringContainsString('snitch-field-prefix', $form);
        $this->assertStringContainsString('https://', $form);
        $this->assertStringContainsString('www.yourbrand.com', $form);
        $this->assertTrue(
            strpos($form, 'Website') < strpos($form, 'Brand name'),
            'Website field should appear before brand name',
        );
    }
}
