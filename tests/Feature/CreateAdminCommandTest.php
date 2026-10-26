<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the shawarma:create-admin Artisan bootstrap command.
 *
 * The command is the only sanctioned way to create the initial administrator.
 * All subsequent accounts must be created through the Admin dashboard.
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    // ─── Success path ─────────────────────────────────────────────────────────

    public function test_create_admin_command_creates_admin_user(): void
    {
        $this->artisan('shawarma:create-admin', [
            '--name'     => 'Jane Admin',
            '--email'    => 'jane@shawarma.test',
            '--password' => 'SecurePassword123!',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Admin account created successfully');

        $user = User::where('email', 'jane@shawarma.test')->firstOrFail();

        $this->assertSame(UserRole::Admin, $user->role);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->must_change_password);
    }

    public function test_admin_password_is_hashed_not_plaintext(): void
    {
        $this->artisan('shawarma:create-admin', [
            '--name'     => 'Hash Test',
            '--email'    => 'hash@shawarma.test',
            '--password' => 'AnotherSecure999!',
        ])->assertSuccessful();

        $user = User::where('email', 'hash@shawarma.test')->firstOrFail();

        // Stored value must never equal the plaintext password
        $this->assertNotSame('AnotherSecure999!', $user->getAttributes()['password']);
        // Hashed password should be verifiable
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('AnotherSecure999!', $user->password));
    }

    // ─── Validation ───────────────────────────────────────────────────────────

    public function test_create_admin_fails_with_short_password(): void
    {
        $this->artisan('shawarma:create-admin', [
            '--name'     => 'Short Pass',
            '--email'    => 'short@shawarma.test',
            '--password' => 'tooshort',   // 8 chars — below 12-char minimum
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'short@shawarma.test']);
    }

    public function test_create_admin_fails_with_invalid_email(): void
    {
        $this->artisan('shawarma:create-admin', [
            '--name'     => 'Bad Email',
            '--email'    => 'not-an-email',
            '--password' => 'SecurePassword123!',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'not-an-email']);
    }

    public function test_create_admin_fails_with_duplicate_email(): void
    {
        // First creation succeeds — no existing admin, no --force needed
        $this->artisan('shawarma:create-admin', [
            '--name'     => 'First Admin',
            '--email'    => 'admin@shawarma.test',
            '--password' => 'SecurePassword123!',
        ])->assertSuccessful();

        // Second creation with the same email: --force triggers confirmation, then fails email validation
        $this->artisan('shawarma:create-admin', [
            '--name'     => 'Duplicate Admin',
            '--email'    => 'admin@shawarma.test',
            '--password' => 'AnotherPass456!',
            '--force'    => true,
        ])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertFailed();

        // Only one user with that email should exist
        $this->assertSame(1, User::where('email', 'admin@shawarma.test')->count());
    }

    // ─── Duplicate-admin guard ────────────────────────────────────────────────

    public function test_command_refuses_second_admin_without_force(): void
    {
        // Create first admin
        User::factory()->admin()->create(['email' => 'first@shawarma.test']);

        // Attempt to create a second admin without --force should fail
        $this->artisan('shawarma:create-admin', [
            '--name'     => 'Second Admin',
            '--email'    => 'second@shawarma.test',
            '--password' => 'SecurePassword123!',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'second@shawarma.test']);
    }

    public function test_command_allows_second_admin_with_force(): void
    {
        // Create first admin
        User::factory()->admin()->create(['email' => 'first@shawarma.test']);

        // --force allows a second admin (but prompts confirmation; we auto-confirm in tests)
        $this->artisan('shawarma:create-admin', [
            '--name'     => 'Second Admin',
            '--email'    => 'second@shawarma.test',
            '--password' => 'SecurePassword123!',
            '--force'    => true,
        ])
            ->expectsConfirmation('Continue?', 'yes')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'second@shawarma.test', 'role' => 'admin']);
        $this->assertSame(2, User::where('role', UserRole::Admin)->count());
    }

    // ─── No public registration ───────────────────────────────────────────────

    public function test_public_register_route_does_not_exist(): void
    {
        $this->get('/register')->assertStatus(404);
    }

    public function test_public_register_post_does_not_exist(): void
    {
        $this->post('/register', [
            'name'                  => 'Intruder',
            'email'                 => 'intruder@example.com',
            'password'              => 'password12345',
            'password_confirmation' => 'password12345',
        ])->assertStatus(404);
    }

    // ─── Admin role is enforced ───────────────────────────────────────────────

    public function test_created_admin_can_access_admin_dashboard(): void
    {
        $this->artisan('shawarma:create-admin', [
            '--name'     => 'Dashboard Admin',
            '--email'    => 'dashboard@shawarma.test',
            '--password' => 'SecurePassword123!',
        ])->assertSuccessful();

        $admin = User::where('email', 'dashboard@shawarma.test')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    public function test_created_admin_cannot_be_employee(): void
    {
        $this->artisan('shawarma:create-admin', [
            '--name'     => 'Role Check',
            '--email'    => 'rolecheck@shawarma.test',
            '--password' => 'SecurePassword123!',
        ])->assertSuccessful();

        $user = User::where('email', 'rolecheck@shawarma.test')->firstOrFail();

        $this->assertTrue($user->isAdmin());
        $this->assertFalse($user->isEmployee());
    }
}
