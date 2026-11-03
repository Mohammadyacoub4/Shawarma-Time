<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account(): void
    {
        // Ensure there is another admin so this is not the last remaining admin
        User::factory()->admin()->create();
        $user = User::factory()->admin()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }

    public function test_last_remaining_admin_cannot_delete_their_account(): void
    {
        $onlyAdmin = User::factory()->admin()->create();

        $response = $this
            ->actingAs($onlyAdmin)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($onlyAdmin->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account(): void
    {
        $user = User::factory()->admin()->create();

        $response = $this
            ->actingAs($user)
            ->from('/profile')
            ->delete('/profile', [
                'password' => 'wrong-password',
            ]);

        $response
            ->assertSessionHasErrorsIn('userDeletion', 'password')
            ->assertRedirect('/profile');

        $this->assertNotNull($user->fresh());
    }

    public function test_staff_employee_cannot_delete_their_account(): void
    {
        $employee = User::factory()->employee()->create();

        $response = $this
            ->actingAs($employee)
            ->delete('/profile', [
                'password' => 'password',
            ]);

        $response->assertForbidden();
        $this->assertNotNull($employee->fresh());
    }

    public function test_last_remaining_admin_cannot_be_deleted_via_model_directly(): void
    {
        $onlyAdmin = User::factory()->admin()->create();

        $this->assertSame(1, User::where('role', \App\Enums\UserRole::Admin)->count());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot delete the last remaining administrator account.');

        try {
            $onlyAdmin->delete();
        } finally {
            $this->assertNotNull($onlyAdmin->fresh());
            $this->assertDatabaseHas('users', ['id' => $onlyAdmin->id]);
            $this->assertSame(1, User::where('role', \App\Enums\UserRole::Admin)->count());
        }
    }

    public function test_admin_can_be_deleted_via_model_when_multiple_admins_exist(): void
    {
        $admin1 = User::factory()->admin()->create();
        $admin2 = User::factory()->admin()->create();

        $this->assertSame(2, User::where('role', \App\Enums\UserRole::Admin)->count());

        $admin1->delete();

        $this->assertNull($admin1->fresh());
        $this->assertNotNull($admin2->fresh());
        $this->assertSame(1, User::where('role', \App\Enums\UserRole::Admin)->count());
    }
}
