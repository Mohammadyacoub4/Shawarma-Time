<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_login_screen_does_not_contain_remember_me_checkbox(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertDontSee('Remember me');
        $response->assertDontSee('name="remember"', false);
    }

    public function test_login_does_not_create_persistent_remember_cookie_even_if_submitted(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => 'on',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));

        // Assert no remember cookie exists in response cookies
        $recallerName = \Illuminate\Support\Facades\Auth::guard('web')->getRecallerName();
        $response->assertCookieMissing($recallerName);
    }

    public function test_session_lifetime_is_configured_to_thirty_minutes(): void
    {
        $this->assertSame(30, (int) config('session.lifetime'));
    }
}
