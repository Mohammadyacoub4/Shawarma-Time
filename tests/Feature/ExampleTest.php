<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Basic application smoke test.
 * The root URL redirects unauthenticated users to /login — this is the correct behaviour.
 */
class ExampleTest extends TestCase
{
    use RefreshDatabase;
    public function test_the_root_url_redirects_unauthenticated_users_to_login(): void
    {
        // Root '/' should redirect to the login page for unauthenticated users
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_login_page_is_accessible(): void
    {
        $this->get(route('login'))->assertOk();
    }

    public function test_guest_layout_does_not_load_alpine_from_cdn(): void
    {
        $response = $this->get(route('login'));
        $response->assertOk();
        $response->assertDontSee('unpkg.com/alpinejs', false);
    }

    public function test_authenticated_layout_does_not_load_alpine_from_cdn(): void
    {
        $user = \App\Models\User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get(route('employee.dashboard'));
        $response->assertOk();
        $response->assertDontSee('unpkg.com/alpinejs', false);
    }
}
