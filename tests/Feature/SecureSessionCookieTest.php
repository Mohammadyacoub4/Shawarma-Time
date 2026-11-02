<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Tests\TestCase;

class SecureSessionCookieTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TrustProxies::flushState();
        parent::tearDown();
    }

    /**
     * Evaluates config/session.php in an isolated environment state.
     */
    private function loadSessionConfigWithEnv(?string $appEnv, ?string $sessionSecureCookie): array
    {
        $backupAppEnv = getenv('APP_ENV');
        $backupSecure = getenv('SESSION_SECURE_COOKIE');

        try {
            if ($appEnv !== null) {
                putenv("APP_ENV={$appEnv}");
                $_ENV['APP_ENV'] = $appEnv;
                $_SERVER['APP_ENV'] = $appEnv;
            } else {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
            }

            if ($sessionSecureCookie !== null) {
                putenv("SESSION_SECURE_COOKIE={$sessionSecureCookie}");
                $_ENV['SESSION_SECURE_COOKIE'] = $sessionSecureCookie;
                $_SERVER['SESSION_SECURE_COOKIE'] = $sessionSecureCookie;
            } else {
                putenv('SESSION_SECURE_COOKIE');
                unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
            }

            return require base_path('config/session.php');
        } finally {
            if ($backupAppEnv === false) {
                putenv('APP_ENV');
                unset($_ENV['APP_ENV'], $_SERVER['APP_ENV']);
            } else {
                putenv("APP_ENV={$backupAppEnv}");
                $_ENV['APP_ENV'] = $backupAppEnv;
                $_SERVER['APP_ENV'] = $backupAppEnv;
            }

            if ($backupSecure === false) {
                putenv('SESSION_SECURE_COOKIE');
                unset($_ENV['SESSION_SECURE_COOKIE'], $_SERVER['SESSION_SECURE_COOKIE']);
            } else {
                putenv("SESSION_SECURE_COOKIE={$backupSecure}");
                $_ENV['SESSION_SECURE_COOKIE'] = $backupSecure;
                $_SERVER['SESSION_SECURE_COOKIE'] = $backupSecure;
            }
        }
    }

    public function test_production_defaults_session_secure_to_true_when_unset(): void
    {
        $config = $this->loadSessionConfigWithEnv('production', null);

        $this->assertTrue(
            $config['secure'],
            'When APP_ENV is production and SESSION_SECURE_COOKIE is unset, session.secure must default to true.'
        );
    }

    public function test_local_and_testing_default_session_secure_to_null_when_unset(): void
    {
        $localConfig = $this->loadSessionConfigWithEnv('local', null);
        $this->assertNull(
            $localConfig['secure'],
            'When APP_ENV is local and SESSION_SECURE_COOKIE is unset, session.secure must default to null.'
        );

        $testingConfig = $this->loadSessionConfigWithEnv('testing', null);
        $this->assertNull(
            $testingConfig['secure'],
            'When APP_ENV is testing and SESSION_SECURE_COOKIE is unset, session.secure must default to null.'
        );
    }

    public function test_explicit_session_secure_cookie_overrides_environment_defaults(): void
    {
        // Explicit false overrides production default
        $prodExplicitFalse = $this->loadSessionConfigWithEnv('production', 'false');
        $this->assertFalse(
            $prodExplicitFalse['secure'],
            'Explicit false must override production default.'
        );

        // Explicit true overrides local default
        $localExplicitTrue = $this->loadSessionConfigWithEnv('local', 'true');
        $this->assertTrue(
            $localExplicitTrue['secure'],
            'Explicit true must override local default.'
        );
    }

    public function test_secure_cookie_is_present_on_both_http_and_https_when_secure_is_true(): void
    {
        config(['session.secure' => true]);

        // HTTP request
        $httpResponse = $this->get('http://localhost/');
        $httpCookie = $httpResponse->getCookie(config('session.cookie'));
        $this->assertNotNull($httpCookie);
        $this->assertTrue($httpCookie->isSecure(), 'Session cookie must have Secure attribute on HTTP when session.secure is true.');

        // HTTPS request
        $httpsResponse = $this->get('https://localhost/');
        $httpsCookie = $httpsResponse->getCookie(config('session.cookie'));
        $this->assertNotNull($httpsCookie);
        $this->assertTrue($httpsCookie->isSecure(), 'Session cookie must have Secure attribute on HTTPS when session.secure is true.');
    }

    public function test_secure_cookie_is_omitted_on_both_http_and_https_when_secure_is_false(): void
    {
        config(['session.secure' => false]);

        // HTTP request
        $httpResponse = $this->get('http://localhost/');
        $httpCookie = $httpResponse->getCookie(config('session.cookie'));
        $this->assertNotNull($httpCookie);
        $this->assertFalse($httpCookie->isSecure(), 'Session cookie must NOT have Secure attribute on HTTP when session.secure is false.');

        // HTTPS request
        $httpsResponse = $this->get('https://localhost/');
        $httpsCookie = $httpsResponse->getCookie(config('session.cookie'));
        $this->assertNotNull($httpsCookie);
        $this->assertFalse($httpsCookie->isSecure(), 'Session cookie must NOT have Secure attribute on HTTPS when session.secure is false.');
    }

    public function test_secure_cookie_is_omitted_on_http_and_enabled_on_https_when_secure_is_null(): void
    {
        config(['session.secure' => null]);

        // Direct HTTP request
        $httpResponse = $this->get('http://localhost/');
        $httpCookie = $httpResponse->getCookie(config('session.cookie'));
        $this->assertNotNull($httpCookie);
        $this->assertFalse($httpCookie->isSecure(), 'Session cookie must NOT have Secure attribute on HTTP when session.secure is null.');

        // Direct HTTPS request
        $httpsResponse = $this->get('https://localhost/');
        $httpsCookie = $httpsResponse->getCookie(config('session.cookie'));
        $this->assertNotNull($httpsCookie);
        $this->assertTrue($httpsCookie->isSecure(), 'Session cookie must have Secure attribute on HTTPS when session.secure is null.');
    }

    public function test_secure_cookie_is_enabled_for_https_behind_correctly_trusted_proxy_when_secure_is_null(): void
    {
        config(['session.secure' => null]);
        TrustProxies::at('10.0.0.1');

        $response = $this->withServerVariables([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.195',
        ])->get('http://localhost/');

        $cookie = $response->getCookie(config('session.cookie'));
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isSecure(), 'Session cookie must have Secure attribute for HTTPS request through a trusted proxy.');
    }

    public function test_existing_session_configuration_and_login_behavior_remain_functional(): void
    {
        // Session security settings integrity
        $this->assertTrue((bool) config('session.http_only'), 'session.http_only must remain true.');
        $this->assertSame('lax', config('session.same_site'), 'session.same_site must remain lax.');
        $this->assertSame(30, (int) config('session.lifetime'), 'session.lifetime must remain configured at 30 minutes.');

        // Authentication functionality verification
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }
}

