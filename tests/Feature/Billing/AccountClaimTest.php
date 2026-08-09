<?php

namespace Tests\Feature\Billing;

use App\Services\Billing\AccountClaimService;
use App\Services\Billing\UsageBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\WorkOS\User as WorkOsUser;
use Tests\TestCase;

class AccountClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_page_renders_for_valid_token(): void
    {
        $created = app(AccountClaimService::class)->createAgentAccount('Agent', 'claim-me@example.com');

        $this->get(route('claim.show', $created['user']->claim_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('claim/Show')
                ->where('email', 'claim-me@example.com'));
    }

    public function test_claim_grants_bonus_once(): void
    {
        $created = app(AccountClaimService::class)->createAgentAccount('Agent', 'claim-bonus@example.com');
        $user = $created['user'];

        $workOs = new WorkOsUser(
            id: 'workos_claim_1',
            organizationId: null,
            firstName: 'Toby',
            lastName: 'Claim',
            email: 'claim-bonus@example.com',
            avatar: null,
        );

        $claimed = app(AccountClaimService::class)->claim($user, $workOs);

        $this->assertTrue($claimed->isClaimed());
        $this->assertSame(500, app(UsageBillingService::class)->balancePence($claimed));

        $this->expectException(\RuntimeException::class);
        app(AccountClaimService::class)->claim($claimed->fresh(), $workOs);
    }
}
