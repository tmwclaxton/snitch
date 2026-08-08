<?php

namespace Tests\Feature\Marketing;

use App\Mail\Marketing\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ContactRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_throttle_keys_on_cloudflare_connecting_ip(): void
    {
        Mail::fake();
        RateLimiter::clear('contact');

        $payload = [
            'name' => 'Rate Limit',
            'email' => 'rate@example.com',
            'message' => 'Contact rate limit probe.',
        ];

        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders([
                'CF-Connecting-IP' => '198.51.100.50',
                'X-Forwarded-For' => '203.0.113.'.(100 + $i),
            ])->post(route('contact.store'), $payload)->assertRedirect();
        }

        $this->withHeaders([
            'CF-Connecting-IP' => '198.51.100.50',
            'X-Forwarded-For' => '203.0.113.250',
        ])->post(route('contact.store'), $payload)->assertStatus(429);

        $this->withHeaders([
            'CF-Connecting-IP' => '198.51.100.51',
            'X-Forwarded-For' => '203.0.113.250',
        ])->post(route('contact.store'), $payload)->assertRedirect();

        Mail::assertSent(ContactMessage::class, 11);
    }
}
