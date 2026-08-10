<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Stripe\Customer;
use Stripe\Exception\InvalidRequestException;
use Tests\TestCase;

class StripeCustomerRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_or_get_stripe_customer_recreates_when_stored_id_is_missing(): void
    {
        $base = User::factory()->create([
            'stripe_id' => 'cus_missing_from_live',
        ]);

        $user = new class extends User
        {
            protected $table = 'users';

            public $timestamps = false;

            public function asStripeCustomer(array $expand = [])
            {
                throw InvalidRequestException::factory(
                    "No such customer: 'cus_missing_from_live'",
                    404,
                    '{}',
                    ['error' => ['message' => "No such customer: 'cus_missing_from_live'"]],
                    null,
                    'resource_missing',
                );
            }

            public function createAsStripeCustomer(array $options = [], array $requestOptions = [])
            {
                $customer = Customer::constructFrom([
                    'id' => 'cus_live_replacement',
                    'object' => 'customer',
                    'email' => $this->email,
                ]);

                $this->forceFill(['stripe_id' => $customer->id])->save();

                return $customer;
            }
        };

        $user->forceFill($base->getAttributes());
        $user->exists = true;
        $user->syncOriginal();

        $customer = $user->createOrGetStripeCustomer();

        $this->assertSame('cus_live_replacement', $customer->id);
        $this->assertSame('cus_live_replacement', $base->fresh()->stripe_id);
    }
}
