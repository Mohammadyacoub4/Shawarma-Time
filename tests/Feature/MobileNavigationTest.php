<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create([
            'name' => 'Tariq Admin',
            'email' => 'tariq.admin@shawarma.test',
        ]);

        $this->employee = User::factory()->employee()->create([
            'name' => 'Sami Employee',
            'email' => 'sami.employee@shawarma.test',
        ]);
    }

    public function test_admin_can_see_account_info_profile_and_logout_in_english(): void
    {
        $response = $this->actingAs($this->admin)
            ->withSession(['locale' => 'en'])
            ->get(route('admin.dashboard'));

        $response->assertOk();
        $content = $response->getContent();

        // Verify elements are present in the response
        $response->assertSee('Tariq Admin');
        $response->assertSee('Admin');
        $response->assertSee(route('profile.edit'));
        $response->assertSee('Profile');
        $response->assertSee(route('logout'));
        $response->assertSee('Sign Out');

        // Admin-specific Navigation Links
        $response->assertSee(route('admin.employees.index'));
        $response->assertSee(route('admin.schedules.index'));
        $response->assertSee(route('admin.timesheets.index'));
        $response->assertSee(route('admin.reports.index'));

        // Does NOT see employee-only links
        $response->assertDontSee(route('employee.timesheets.create'));

        // Specifically verify the mobile drawer contains profile and logout
        $mobileDrawer = $this->extractMobileDrawer($content);
        $this->assertStringContainsString('Tariq Admin', $mobileDrawer);
        $this->assertStringContainsString('Admin', $mobileDrawer);
        $this->assertStringContainsString(route('profile.edit'), $mobileDrawer);
        $this->assertStringContainsString('Profile', $mobileDrawer);
        $this->assertStringContainsString(route('logout'), $mobileDrawer);
        $this->assertStringContainsString('Sign Out', $mobileDrawer);

        // Verify both desktop and mobile instances exist (2 occurrences)
        $this->assertSame(2, substr_count($content, route('profile.edit')));
        $this->assertSame(2, substr_count($content, route('logout')));
    }

    public function test_admin_can_see_account_info_profile_and_logout_in_arabic(): void
    {
        $response = $this->actingAs($this->admin)
            ->withSession(['locale' => 'ar'])
            ->get(route('admin.dashboard'));

        $response->assertOk();
        $content = $response->getContent();

        // Account Identity in Arabic
        $response->assertSee('Tariq Admin');
        $response->assertSee('مدير النظام');

        // Profile & Logout in Arabic
        $response->assertSee(route('profile.edit'));
        $response->assertSee('الملف الشخصي');
        $response->assertSee(route('logout'));
        $response->assertSee('تسجيل الخروج');

        // Specifically verify the mobile drawer contains Arabic labels
        $mobileDrawer = $this->extractMobileDrawer($content);
        $this->assertStringContainsString('Tariq Admin', $mobileDrawer);
        $this->assertStringContainsString('مدير النظام', $mobileDrawer);
        $this->assertStringContainsString(route('profile.edit'), $mobileDrawer);
        $this->assertStringContainsString('الملف الشخصي', $mobileDrawer);
        $this->assertStringContainsString(route('logout'), $mobileDrawer);
        $this->assertStringContainsString('تسجيل الخروج', $mobileDrawer);
    }

    public function test_employee_can_see_account_info_profile_and_logout_in_english(): void
    {
        $response = $this->actingAs($this->employee)
            ->withSession(['locale' => 'en'])
            ->get(route('employee.dashboard'));

        $response->assertOk();
        $content = $response->getContent();

        // Account Identity
        $response->assertSee('Sami Employee');
        $response->assertSee('Staff');

        // Profile & Logout Links
        $response->assertSee(route('profile.edit'));
        $response->assertSee('Profile');
        $response->assertSee(route('logout'));
        $response->assertSee('Sign Out');

        // Employee-specific Navigation Links
        $response->assertSee(route('employee.schedules.index'));
        $response->assertSee(route('employee.timesheets.create'));

        // Does NOT see admin-only links
        $response->assertDontSee(route('admin.employees.index'));
        $response->assertDontSee(route('admin.reports.index'));

        // Specifically verify the mobile drawer contains profile and logout
        $mobileDrawer = $this->extractMobileDrawer($content);
        $this->assertStringContainsString('Sami Employee', $mobileDrawer);
        $this->assertStringContainsString('Staff', $mobileDrawer);
        $this->assertStringContainsString(route('profile.edit'), $mobileDrawer);
        $this->assertStringContainsString('Profile', $mobileDrawer);
        $this->assertStringContainsString(route('logout'), $mobileDrawer);
        $this->assertStringContainsString('Sign Out', $mobileDrawer);
    }

    public function test_employee_can_see_account_info_profile_and_logout_in_arabic(): void
    {
        $response = $this->actingAs($this->employee)
            ->withSession(['locale' => 'ar'])
            ->get(route('employee.dashboard'));

        $response->assertOk();
        $content = $response->getContent();

        // Account Identity in Arabic
        $response->assertSee('Sami Employee');
        $response->assertSee('موظف');

        // Profile & Logout in Arabic
        $response->assertSee(route('profile.edit'));
        $response->assertSee('الملف الشخصي');
        $response->assertSee(route('logout'));
        $response->assertSee('تسجيل الخروج');

        // Specifically verify the mobile drawer contains Arabic labels
        $mobileDrawer = $this->extractMobileDrawer($content);
        $this->assertStringContainsString('Sami Employee', $mobileDrawer);
        $this->assertStringContainsString('موظف', $mobileDrawer);
        $this->assertStringContainsString(route('profile.edit'), $mobileDrawer);
        $this->assertStringContainsString('الملف الشخصي', $mobileDrawer);
        $this->assertStringContainsString(route('logout'), $mobileDrawer);
        $this->assertStringContainsString('تسجيل الخروج', $mobileDrawer);
    }

    private function extractMobileDrawer(string $html): string
    {
        if (preg_match('/<div[^>]*class="[^"]*md:hidden[^"]*"[^>]*>(.*?)<\/div>\s*<\/nav>/s', $html, $matches)) {
            return $matches[1];
        }

        $this->fail('Mobile navigation drawer container (md:hidden) not found in response HTML.');
    }

    public function test_logout_form_uses_post_and_includes_csrf_protection(): void
    {
        $response = $this->actingAs($this->employee)
            ->get(route('employee.dashboard'));

        $response->assertOk();

        $content = $response->getContent();

        // Assert that a form with action pointing to logout route and method POST exists
        $this->assertMatchesRegularExpression(
            '#<form[^>]+method=["\']POST["\'][^>]+action=["\'][^"\']*logout["\']#i',
            $content
        );

        // Assert CSRF token input is present
        $this->assertStringContainsString('name="_token"', $content);
    }

    public function test_avatar_initial_displays_first_character_for_arabic_and_latin_names(): void
    {
        $arabicUser = User::factory()->employee()->create([
            'name' => 'خالد منصور',
            'email' => 'khaled@shawarma.test',
        ]);

        $response = $this->actingAs($arabicUser)
            ->get(route('employee.dashboard'));

        $response->assertOk();
        $response->assertSee('خ');
        $response->assertSee('خالد منصور');
    }

    public function test_profile_and_logout_remain_fully_functional(): void
    {
        // 1. Profile edit page is accessible
        $profileResponse = $this->actingAs($this->employee)
            ->get(route('profile.edit'));
        $profileResponse->assertOk();

        // 2. Logout post action terminates session and redirects
        $logoutResponse = $this->actingAs($this->employee)
            ->post(route('logout'));

        $logoutResponse->assertRedirect('/');
        $this->assertGuest();
    }
}
