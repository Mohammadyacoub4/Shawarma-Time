<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/reset-password', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $response
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('login'));

            return true;
        });
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->post('/forgot-password', ['email' => 'unknown@example.com']);
        }

        $response = $this->post('/forgot-password', ['email' => 'unknown@example.com']);
        $response->assertStatus(429);
    }

    public function test_reset_password_is_rate_limited(): void
    {
        $payload = [
            'token' => 'sample-reset-token',
            'email' => 'employee@shawarmatime.ca',
            'password' => 'NewSecurePassword123!',
            'password_confirmation' => 'NewSecurePassword123!',
        ];

        for ($i = 0; $i < 6; $i++) {
            $this->post('/reset-password', $payload);
        }

        $response = $this->post('/reset-password', $payload);
        $response->assertStatus(429);
    }

    public function test_forgot_password_returns_identical_generic_response_for_existing_and_nonexistent_email(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'real-staff@shawarmatime.ca']);

        // 1. Existing user receives generic success status and no email errors
        $existingResponse = $this->post('/forgot-password', ['email' => $user->email]);
        $existingResponse->assertRedirect();
        $existingResponse->assertSessionHasNoErrors();
        $existingResponse->assertSessionHas('status', __('passwords.sent'));
        Notification::assertSentTo($user, ResetPassword::class);

        // 2. Non-existent email receives the exact same generic success status and no email errors
        $nonExistentResponse = $this->post('/forgot-password', ['email' => 'doesnotexist@shawarmatime.ca']);
        $nonExistentResponse->assertRedirect();
        $nonExistentResponse->assertSessionHasNoErrors();
        $nonExistentResponse->assertSessionHas('status', __('passwords.sent'));

        // Confirm notification was NOT sent to non-existent account
        Notification::assertSentTimes(ResetPassword::class, 1);
    }

    public function test_forgot_password_returns_generic_response_under_broker_throttle(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'throttled-staff@shawarmatime.ca']);

        // First request sends email and creates token
        $firstResponse = $this->post('/forgot-password', ['email' => $user->email]);
        $firstResponse->assertRedirect();
        $firstResponse->assertSessionHas('status', __('passwords.sent'));
        $firstResponse->assertSessionHasNoErrors();

        // Immediate second request triggers broker 60-second cooldown, but must still return generic response without error
        $secondResponse = $this->post('/forgot-password', ['email' => $user->email]);
        $secondResponse->assertRedirect();
        $secondResponse->assertSessionHas('status', __('passwords.sent'));
        $secondResponse->assertSessionHasNoErrors();

        // Notification was only sent once (broker throttled second generation)
        Notification::assertSentTimes(ResetPassword::class, 1);
    }
}
