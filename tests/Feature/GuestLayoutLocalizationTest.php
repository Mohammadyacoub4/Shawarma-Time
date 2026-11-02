<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestLayoutLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_layout_defaults_to_english_lang_and_ltr_dir(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('<html lang="en" dir="ltr">', false);
        $response->assertSee('Staff Portal');
        $response->assertSee('Staff Scheduling & Timesheet Portal');
        $response->assertSee('Welcome back');
        $response->assertSee('Sign in to your Shawarma Time staff account');
        $response->assertSee(route('locale.switch', 'ar'));
        $response->assertSee('عربي');
    }

    public function test_guest_layout_renders_arabic_lang_and_rtl_dir(): void
    {
        $response = $this->withSession(['locale' => 'ar', 'direction' => 'rtl'])
            ->get('/login');

        $response->assertOk();
        $response->assertSee('<html lang="ar" dir="rtl">', false);
        $response->assertSee('بوابة الموظفين');
        $response->assertSee('نظام إدارة الجداول وحضور الموظفين');
        $response->assertSee('مرحباً بعودتك');
        $response->assertSee('البريد الإلكتروني');
        $response->assertSee('كلمة المرور');
        $response->assertSee('تسجيل الدخول');
        $response->assertSee(route('locale.switch', 'en'));
        $response->assertSee('English');
    }

    public function test_guest_can_switch_locale_to_arabic_and_persists_in_session(): void
    {
        $response = $this->from('/login')->get('/locale/ar');

        $response->assertRedirect('/login');
        $response->assertSessionHas('locale', 'ar');
        $response->assertSessionHas('direction', 'rtl');

        // Follow up request in same session renders in Arabic
        $followUp = $this->withSession([
            'locale' => 'ar',
            'direction' => 'rtl',
        ])->get('/login');

        $followUp->assertOk();
        $followUp->assertSee('<html lang="ar" dir="rtl">', false);
        $followUp->assertSee('تسجيل الدخول');
    }

    public function test_guest_can_switch_locale_from_arabic_back_to_english(): void
    {
        $response = $this->withSession(['locale' => 'ar', 'direction' => 'rtl'])
            ->from('/login')
            ->get('/locale/en');

        $response->assertRedirect('/login');
        $response->assertSessionHas('locale', 'en');
        $response->assertSessionHas('direction', 'ltr');

        $followUp = $this->withSession([
            'locale' => 'en',
            'direction' => 'ltr',
        ])->get('/login');

        $followUp->assertOk();
        $followUp->assertSee('<html lang="en" dir="ltr">', false);
        $followUp->assertSee('Sign In');
    }

    public function test_guest_can_switch_locale_from_forgot_password_page(): void
    {
        $response = $this->from('/forgot-password')->get('/locale/ar');

        $response->assertRedirect('/forgot-password');
        $response->assertSessionHas('locale', 'ar');

        $followUp = $this->withSession([
            'locale' => 'ar',
            'direction' => 'rtl',
        ])->get('/forgot-password');

        $followUp->assertOk();
        $followUp->assertSee('<html lang="ar" dir="rtl">', false);
        $followUp->assertSee('نسيت كلمة المرور؟');
        $followUp->assertSee('إرسال رابط إعادة التعيين');
    }

    public function test_guest_can_switch_locale_from_reset_password_page(): void
    {
        $token = 'dummy-token';
        $resetUrl = '/reset-password/' . $token . '?email=test%40example.com';

        $response = $this->from($resetUrl)->get('/locale/ar');

        $response->assertRedirect($resetUrl);
        $response->assertSessionHas('locale', 'ar');

        $followUp = $this->withSession([
            'locale' => 'ar',
            'direction' => 'rtl',
        ])->get($resetUrl);

        $followUp->assertOk();
        $followUp->assertSee('<html lang="ar" dir="rtl">', false);
        $followUp->assertSee('إعادة تعيين كلمة المرور');
    }

    public function test_locale_switch_rejects_external_referer_open_redirect(): void
    {
        $response = $this->withHeaders([
            'Referer' => 'https://evil.com/phishing-attack',
        ])->get('/locale/ar');

        $response->assertStatus(302);
        $this->assertStringNotContainsString('evil.com', (string) $response->headers->get('Location'));
        $response->assertRedirect(route('login'));
    }

    public function test_locale_switch_rejects_protocol_relative_referer(): void
    {
        $response = $this->withHeaders([
            'Referer' => '//evil.com/stealth-redirect',
        ])->get('/locale/ar');

        $response->assertStatus(302);
        $this->assertStringNotContainsString('evil.com', (string) $response->headers->get('Location'));
        $response->assertRedirect(route('login'));
    }

    public function test_locale_switch_rejects_backslash_prefixed_referer(): void
    {
        $response = $this->withHeaders([
            'Referer' => '/\\evil.com/bypass',
        ])->get('/locale/ar');

        $response->assertStatus(302);
        $this->assertStringNotContainsString('evil.com', (string) $response->headers->get('Location'));
        $response->assertRedirect(route('login'));
    }

    public function test_locale_switch_rejects_external_subdomain_referer(): void
    {
        $response = $this->withHeaders([
            'Referer' => 'http://localhost.evil.com/login',
        ])->get('/locale/ar');

        $response->assertStatus(302);
        $this->assertStringNotContainsString('evil.com', (string) $response->headers->get('Location'));
        $response->assertRedirect(route('login'));
    }

    public function test_locale_switch_prevents_looping_to_itself(): void
    {
        $response = $this->withHeaders([
            'Referer' => url('/locale/en'),
        ])->get('/locale/ar');

        $response->assertStatus(302);
        $response->assertRedirect(route('login'));
    }

    public function test_locale_switch_falls_back_to_login_when_no_referer_present(): void
    {
        $response = $this->get('/locale/ar');

        $response->assertStatus(302);
        $response->assertRedirect(route('login'));
    }

    public function test_invalid_locale_does_not_change_session(): void
    {
        $response = $this->withSession(['locale' => 'en', 'direction' => 'ltr'])
            ->from('/login')
            ->get('/locale/fr');

        $response->assertRedirect('/login');
        $response->assertSessionHas('locale', 'en');
        $response->assertSessionHas('direction', 'ltr');
    }

    public function test_guest_can_authenticate_normally_under_arabic_locale(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['locale' => 'ar', 'direction' => 'rtl'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'password',
            ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_guest_authentication_fails_cleanly_with_invalid_credentials_under_arabic(): void
    {
        $user = User::factory()->create();

        $response = $this->withSession(['locale' => 'ar', 'direction' => 'rtl'])
            ->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
            ]);

        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_public_registration_remains_disabled_under_guest_localization(): void
    {
        $this->get('/register')->assertStatus(404);
        $this->post('/register', ['name' => 'Test'])->assertStatus(404);
    }

    public function test_force_password_change_view_renders_arabic(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $response = $this->actingAs($user)
            ->withSession(['locale' => 'ar', 'direction' => 'rtl'])
            ->get(route('password.force'));

        $response->assertOk();
        $response->assertSee('<html lang="ar" dir="rtl">', false);
        $response->assertSee('لدواعي الأمان');
        $response->assertSee('كلمة المرور الجديدة');
        $response->assertSee('تحديث كلمة المرور');
    }

    public function test_confirm_password_view_renders_arabic(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withSession(['locale' => 'ar', 'direction' => 'rtl'])
            ->get(route('password.confirm'));

        $response->assertOk();
        $response->assertSee('<html lang="ar" dir="rtl">', false);
        $response->assertSee('هذه منطقة آمنة من النظام');
        $response->assertSee('تأكيد');
    }

    public function test_verify_email_view_renders_arabic(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)
            ->withSession(['locale' => 'ar', 'direction' => 'rtl'])
            ->get(route('verification.notice'));

        $response->assertOk();
        $response->assertSee('<html lang="ar" dir="rtl">', false);
        $response->assertSee('شكراً لتسجيلك');
        $response->assertSee('إعادة إرسال البريد الإلكتروني');
        $response->assertSee('تسجيل الخروج');
    }
}

