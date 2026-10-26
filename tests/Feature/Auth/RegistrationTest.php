<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

/**
 * Shawarma Time does not allow public self-registration.
 * Employee accounts are created exclusively by administrators through the Admin dashboard.
 * These tests verify that the public registration endpoints return 404.
 */
class RegistrationTest extends TestCase
{
    public function test_registration_screen_is_not_publicly_accessible(): void
    {
        $this->get('/register')->assertStatus(404);
    }

    public function test_public_registration_post_is_not_routed(): void
    {
        $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password12345',
            'password_confirmation' => 'password12345',
        ])->assertStatus(404);
    }
}
