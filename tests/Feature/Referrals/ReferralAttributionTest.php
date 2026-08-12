<?php

namespace Tests\Feature\Referrals;

use App\Models\ReferralCode;
use App\Models\ReferralVisit;
use App\Models\User;
use App\Services\Billing\AccountClaimService;
use App\Services\Referrals\ReferralAttribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\WorkOS\User as WorkOsUser;
use Tests\TestCase;

class ReferralAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_ref_query_sets_cookie_and_visit(): void
    {
        $referral = ReferralCode::factory()->create(['code' => 'farmbabe']);

        $response = $this->get('/?ref=farmbabe');

        $response->assertOk();
        $response->assertPlainCookie(ReferralAttribution::COOKIE_NAME, 'farmbabe');
        $this->assertSame(1, ReferralVisit::query()->where('referral_code_id', $referral->id)->count());
    }

    public function test_invalid_or_inactive_ref_is_ignored(): void
    {
        ReferralCode::factory()->inactive()->create(['code' => 'paused']);

        $response = $this->get('/?ref=paused');

        $response->assertOk();
        $response->assertCookieMissing(ReferralAttribution::COOKIE_NAME);
        $this->assertSame(0, ReferralVisit::query()->count());
    }

    public function test_cookie_persists_attribution_without_query_param(): void
    {
        ReferralCode::factory()->create(['code' => 'persist']);

        $this->withUnencryptedCookie(ReferralAttribution::COOKIE_NAME, 'persist')
            ->get('/pricing')
            ->assertOk();

        $session = $this->app['session.store'];
        $session->start();
        $session->put(ReferralAttribution::SESSION_KEY, 'persist');

        $request = Request::create('/pricing', 'GET');
        $request->cookies->set(ReferralAttribution::COOKIE_NAME, 'persist');
        $request->setLaravelSession($session);

        $code = app(ReferralAttribution::class)->codeFromRequest($request);

        $this->assertNotNull($code);
        $this->assertSame('persist', $code->code);
    }

    public function test_first_touch_does_not_overwrite_existing_cookie(): void
    {
        ReferralCode::factory()->create(['code' => 'first']);
        $second = ReferralCode::factory()->create(['code' => 'second']);

        $this->withUnencryptedCookie(ReferralAttribution::COOKIE_NAME, 'first')
            ->get('/?ref=second')
            ->assertOk();

        $request = Request::create('/?ref=second', 'GET');
        $request->cookies->set(ReferralAttribution::COOKIE_NAME, 'first');
        $session = $this->app['session.store'];
        $session->start();
        $request->setLaravelSession($session);

        $code = app(ReferralAttribution::class)->codeFromRequest($request);

        $this->assertNotNull($code);
        $this->assertSame('first', $code->code);
        $this->assertSame(1, ReferralVisit::query()->where('referral_code_id', $second->id)->count());
    }

    public function test_bind_to_user_sets_referral_code_id_from_cookie(): void
    {
        $referral = ReferralCode::factory()->create(['code' => 'signup']);
        $user = User::factory()->create(['referral_code_id' => null]);

        $request = Request::create('/', 'GET');
        $request->cookies->set(ReferralAttribution::COOKIE_NAME, 'signup');
        $session = $this->app['session.store'];
        $session->start();
        $request->setLaravelSession($session);

        $bound = app(ReferralAttribution::class)->bindToUser($user, $request);

        $this->assertTrue($bound);
        $this->assertSame($referral->id, $user->fresh()->referral_code_id);
    }

    public function test_claim_path_binds_referral_when_unset(): void
    {
        $referral = ReferralCode::factory()->create(['code' => 'claimme']);
        $created = app(AccountClaimService::class)->createAgentAccount('Agent', 'claim-ref@example.com');

        $session = $this->app['session.store'];
        $session->start();

        $request = Request::create('/claim/'.$created['user']->claim_token, 'GET');
        $request->cookies->set(ReferralAttribution::COOKIE_NAME, 'claimme');
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        $workOs = new WorkOsUser(
            id: 'workos_ref_claim',
            organizationId: null,
            firstName: 'Ref',
            lastName: 'Claim',
            email: 'claim-ref@example.com',
            avatar: null,
        );

        $claimed = app(AccountClaimService::class)->claim($created['user'], $workOs);

        $this->assertSame($referral->id, $claimed->fresh()->referral_code_id);
    }

    public function test_existing_referral_is_not_overwritten(): void
    {
        $first = ReferralCode::factory()->create(['code' => 'first']);
        ReferralCode::factory()->create(['code' => 'second']);

        $user = User::factory()->create(['referral_code_id' => $first->id]);

        $request = Request::create('/', 'GET');
        $request->cookies->set(ReferralAttribution::COOKIE_NAME, 'second');
        $session = $this->app['session.store'];
        $session->start();
        $request->setLaravelSession($session);

        $bound = app(ReferralAttribution::class)->bindToUser($user, $request);

        $this->assertFalse($bound);
        $this->assertSame($first->id, $user->fresh()->referral_code_id);
    }
}
