<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\User;
use App\Services\TemporaryPasswordManager;
use Database\Seeders\BranchSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class EmployeePasswordSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(BranchSeeder::class);
        $this->branch = Branch::where('slug', 'vancouver')->firstOrFail();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Admin User',
            'email' => 'admin@shawarma.test',
        ]);
    }

    public function test_employee_creation_does_not_flash_plaintext_password_in_status(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.employees.store'), [
            'name' => 'John Doe',
            'email' => 'john.doe@example.com',
            'phone' => '+16041234567',
            'branch_ids' => [$this->branch->id],
            'must_change_password' => '1',
        ]);

        $response->assertRedirect(route('admin.employees.index'));
        $response->assertSessionHas('status');
        $response->assertSessionHas('temp_password_pending');

        $employee = User::where('email', 'john.doe@example.com')->firstOrFail();
        $this->assertTrue((bool) $employee->must_change_password);
        $this->assertNotEmpty($employee->password);

        // Crucial security check: Plaintext password must NOT be in the session status message
        $status = session('status');
        $pending = session('temp_password_pending');
        $this->assertIsArray($pending);
        $this->assertNotEmpty($pending['token']);
        $this->assertFalse(isset($pending['password']));

        // Retrieve the generated password once via manager using the token
        $manager = app(TemporaryPasswordManager::class);
        $retrievedPassword = $manager->retrieveOnce($this->admin, $employee, $pending['token']);
        $this->assertNotNull($retrievedPassword);
        $this->assertTrue(Hash::check($retrievedPassword, $employee->password));

        // The status string must NOT contain this plaintext password
        $this->assertStringNotContainsString($retrievedPassword, $status);
    }

    public function test_employee_reset_password_does_not_flash_plaintext_password_in_status(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
            'password' => Hash::make('OldPassword123!'),
            'must_change_password' => false,
        ]);
        $employee->branches()->attach($this->branch->id);

        $response = $this->actingAs($this->admin)->post(route('admin.employees.reset-password.update', $employee), []);

        $response->assertRedirect(route('admin.employees.show', $employee));
        $response->assertSessionHas('status');
        $response->assertSessionHas('temp_password_pending');

        $employee->refresh();
        $this->assertTrue((bool) $employee->must_change_password);

        $status = session('status');
        $pending = session('temp_password_pending');

        $manager = app(TemporaryPasswordManager::class);
        $retrievedPassword = $manager->retrieveOnce($this->admin, $employee, $pending['token']);
        $this->assertNotNull($retrievedPassword);
        $this->assertTrue(Hash::check($retrievedPassword, $employee->password));

        // Plaintext password must not be in status message
        $this->assertStringNotContainsString($retrievedPassword, $status);
    }

    public function test_temporary_password_can_be_retrieved_exactly_once_via_endpoint(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee->branches()->attach($this->branch->id);

        $this->actingAs($this->admin)->post(route('admin.employees.reset-password.update', $employee), []);

        $pending = session('temp_password_pending');
        $token = $pending['token'];

        // First retrieval: Success (200)
        $firstResponse = $this->actingAs($this->admin)->postJson(
            route('admin.employees.retrieve-temporary-password', $employee),
            ['token' => $token]
        );

        $firstResponse->assertOk();
        $firstResponse->assertJsonStructure(['password']);
        $firstResponse->assertHeader('Cache-Control', 'max-age=0, must-revalidate, no-cache, no-store, private');
        $firstResponse->assertHeader('Pragma', 'no-cache');
        $retrievedPassword = $firstResponse->json('password');
        $this->assertTrue(Hash::check($retrievedPassword, $employee->fresh()->password));

        // Second retrieval: Fails with 410 Gone (consumed exactly once)
        $secondResponse = $this->actingAs($this->admin)->postJson(
            route('admin.employees.retrieve-temporary-password', $employee),
            ['token' => $token]
        );

        $secondResponse->assertStatus(410);
    }

    public function test_unauthorized_user_cannot_retrieve_temporary_password(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee->branches()->attach($this->branch->id);

        $this->actingAs($this->admin)->post(route('admin.employees.reset-password.update', $employee), []);
        $token = session('temp_password_pending')['token'];

        $anotherAdmin = User::factory()->admin()->create(['email' => 'admin2@shawarma.test']);
        $unauthorizedEmployee = User::factory()->create(['role' => \App\Enums\UserRole::Employee]);

        // Non-admin employee is forbidden (403)
        $empResponse = $this->actingAs($unauthorizedEmployee)->postJson(
            route('admin.employees.retrieve-temporary-password', $employee),
            ['token' => $token]
        );
        $empResponse->assertForbidden();

        // Another admin attempting to retrieve without owning the token fails (410 gone / invalid ownership)
        $otherAdminResponse = $this->actingAs($anotherAdmin)->postJson(
            route('admin.employees.retrieve-temporary-password', $employee),
            ['token' => $token]
        );
        $otherAdminResponse->assertStatus(410);
    }

    public function test_must_change_password_workflow_forces_redirect_on_login(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
            'password' => Hash::make('TempPassword123!'),
            'must_change_password' => true,
        ]);
        $employee->branches()->attach($this->branch->id);

        // Employee logs in with temporary password
        $loginResponse = $this->post(route('login'), [
            'email' => $employee->email,
            'password' => 'TempPassword123!',
        ]);
        $loginResponse->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($employee);

        // Accessing dashboard redirects to force password change page
        $dashResponse = $this->get(route('dashboard'));
        $dashResponse->assertRedirect(route('password.force'));

        // Employee updates password
        $updateResponse = $this->put(route('password.force.update'), [
            'password' => 'MyNewSecretPass2026!',
            'password_confirmation' => 'MyNewSecretPass2026!',
        ]);
        $updateResponse->assertRedirect(route('dashboard'));

        $employee->refresh();
        $this->assertFalse((bool) $employee->must_change_password);
        $this->assertTrue(Hash::check('MyNewSecretPass2026!', $employee->password));

        // Subsequent visit to dashboard routes to employee.dashboard without redirecting to password.force
        $nextDashResponse = $this->get(route('dashboard'));
        $nextDashResponse->assertRedirect(route('employee.dashboard'));

        $employeeDashboardResponse = $this->get(route('employee.dashboard'));
        $employeeDashboardResponse->assertOk();
    }

    public function test_lock_acquisition_failure_safely_prevents_retrieval(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee->branches()->attach($this->branch->id);

        $manager = app(TemporaryPasswordManager::class);
        $token = $manager->store($this->admin, $employee, 'SecretPassword123!');

        // Pre-acquire the lock externally so that retrieveOnce fails to acquire it
        $lockKey = 'temp_password_token:' . $token . ':lock';
        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 10);
        $this->assertTrue($lock->acquire());

        try {
            // When another process holds the lock, retrieveOnce fails to acquire lock and returns null
            $result = $manager->retrieveOnce($this->admin, $employee, $token);
            $this->assertNull($result);

            // Endpoint also returns 410 when retrieval returns null
            $response = $this->actingAs($this->admin)->postJson(
                route('admin.employees.retrieve-temporary-password', $employee),
                ['token' => $token]
            );
            $response->assertStatus(410);
        } finally {
            $lock->release();
        }
    }

    public function test_retrieval_endpoint_is_rate_limited(): void
    {
        $employee = User::factory()->create([
            'role' => \App\Enums\UserRole::Employee,
            'is_active' => true,
        ]);
        $employee->branches()->attach($this->branch->id);

        // Send 15 requests (allowed)
        for ($i = 0; $i < 15; $i++) {
            $res = $this->actingAs($this->admin)->postJson(
                route('admin.employees.retrieve-temporary-password', $employee),
                ['token' => 'invalid-token-' . $i]
            );
            $res->assertStatus(410);
        }

        // 16th request within 1 minute must be rate limited with HTTP 429
        $throttledResponse = $this->actingAs($this->admin)->postJson(
            route('admin.employees.retrieve-temporary-password', $employee),
            ['token' => 'invalid-token-16']
        );
        $throttledResponse->assertStatus(429);
    }
}
