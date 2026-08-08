<?php

namespace Tests\Feature;

use Tests\TestCase;

class AuthenticateWithoutCodeTest extends TestCase
{
    public function test_authenticate_without_code_redirects_to_login(): void
    {
        $this->get('/authenticate')
            ->assertRedirect(route('login'));
    }

    public function test_authenticate_with_empty_code_redirects_to_login(): void
    {
        $this->get('/authenticate?code=')
            ->assertRedirect(route('login'));
    }
}
