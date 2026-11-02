<?php

namespace Tests\Feature;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

class TrustedProxyConfigurationTest extends TestCase
{
    private string|false $originalEnvTrustedProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvTrustedProxies = getenv('TRUSTED_PROXIES');
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        if ($this->originalEnvTrustedProxies === false) {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        } else {
            putenv("TRUSTED_PROXIES={$this->originalEnvTrustedProxies}");
            $_ENV['TRUSTED_PROXIES'] = $this->originalEnvTrustedProxies;
            $_SERVER['TRUSTED_PROXIES'] = $this->originalEnvTrustedProxies;
        }

        parent::tearDown();
    }

    /**
     * Dispatch a request through the real bootstrap/app.php application stack.
     */
    private function dispatchThroughApp(
        string|array|null $trustedProxiesConfig,
        string $remoteAddr,
        ?string $forwardedFor = null,
        ?string $forwardedProto = null
    ): Request {
        TrustProxies::flushState();

        if (is_string($trustedProxiesConfig)) {
            putenv("TRUSTED_PROXIES={$trustedProxiesConfig}");
            $_ENV['TRUSTED_PROXIES'] = $trustedProxiesConfig;
            $_SERVER['TRUSTED_PROXIES'] = $trustedProxiesConfig;
        } else {
            putenv('TRUSTED_PROXIES');
            unset($_ENV['TRUSTED_PROXIES'], $_SERVER['TRUSTED_PROXIES']);
        }

        $app = require base_path('bootstrap/app.php');

        if (is_array($trustedProxiesConfig)) {
            $app->booting(function ($app) use ($trustedProxiesConfig) {
                $app['config']->set('app.trusted_proxies', $trustedProxiesConfig);
            });
        }

        $kernel = $app->make(Kernel::class);

        $server = ['REMOTE_ADDR' => $remoteAddr];
        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }
        if ($forwardedProto !== null) {
            $server['HTTP_X_FORWARDED_PROTO'] = $forwardedProto;
        }

        $request = Request::create('/up', 'GET', [], [], [], $server);
        $kernel->handle($request);

        return $request;
    }

    public function test_wildcard_asterisk_string_trusts_calling_proxy_and_resolves_client_ip(): void
    {
        $request = $this->dispatchThroughApp('*', '10.0.0.1', '203.0.113.195');

        $this->assertTrue($request->isFromTrustedProxy(), 'Request from calling proxy should be trusted when TRUSTED_PROXIES="*"');
        $this->assertSame('203.0.113.195', $request->getClientIp());
    }

    public function test_wildcard_double_asterisk_string_trusts_calling_proxy_and_resolves_client_ip(): void
    {
        $request = $this->dispatchThroughApp('**', '10.0.0.1', '203.0.113.195');

        $this->assertTrue($request->isFromTrustedProxy(), 'Request from calling proxy should be trusted when TRUSTED_PROXIES="**"');
        $this->assertSame('203.0.113.195', $request->getClientIp());
    }

    public function test_wildcard_with_whitespace_is_trimmed_and_preserves_wildcard_string(): void
    {
        $request = $this->dispatchThroughApp('  *  ', '172.16.0.50', '198.51.100.22');

        $this->assertTrue($request->isFromTrustedProxy());
        $this->assertSame('198.51.100.22', $request->getClientIp());
    }

    public function test_single_ip_trusts_matching_proxy_and_rejects_untrusted_proxy(): void
    {
        // 1. Matching proxy
        $trustedRequest = $this->dispatchThroughApp('10.0.0.1', '10.0.0.1', '203.0.113.5');
        $this->assertTrue($trustedRequest->isFromTrustedProxy());
        $this->assertSame('203.0.113.5', $trustedRequest->getClientIp());

        // 2. Non-matching proxy (attacker IP)
        $untrustedRequest = $this->dispatchThroughApp('10.0.0.1', '192.168.1.100', '203.0.113.5');
        $this->assertFalse($untrustedRequest->isFromTrustedProxy());
        $this->assertSame('192.168.1.100', $untrustedRequest->getClientIp());
    }

    public function test_comma_separated_trimmed_ips_trust_listed_proxies(): void
    {
        $config = ' 10.0.0.1 , 10.0.0.2 , 10.0.0.3 ';

        // First listed proxy
        $req1 = $this->dispatchThroughApp($config, '10.0.0.1', '203.0.113.10');
        $this->assertTrue($req1->isFromTrustedProxy());
        $this->assertSame('203.0.113.10', $req1->getClientIp());

        // Second listed proxy
        $req2 = $this->dispatchThroughApp($config, '10.0.0.2', '203.0.113.20');
        $this->assertTrue($req2->isFromTrustedProxy());
        $this->assertSame('203.0.113.20', $req2->getClientIp());

        // Unlisted proxy
        $req3 = $this->dispatchThroughApp($config, '10.0.0.99', '203.0.113.30');
        $this->assertFalse($req3->isFromTrustedProxy());
        $this->assertSame('10.0.0.99', $req3->getClientIp());
    }

    public function test_cidr_range_trusts_matching_subnets_and_rejects_outside_ips(): void
    {
        $config = '10.0.0.0/8, 172.16.0.0/12';

        // Within 10.0.0.0/8
        $req1 = $this->dispatchThroughApp($config, '10.254.1.1', '203.0.113.77');
        $this->assertTrue($req1->isFromTrustedProxy());
        $this->assertSame('203.0.113.77', $req1->getClientIp());

        // Within 172.16.0.0/12
        $req2 = $this->dispatchThroughApp($config, '172.20.10.5', '203.0.113.88');
        $this->assertTrue($req2->isFromTrustedProxy());
        $this->assertSame('203.0.113.88', $req2->getClientIp());

        // Outside subnets
        $req3 = $this->dispatchThroughApp($config, '192.168.1.1', '203.0.113.99');
        $this->assertFalse($req3->isFromTrustedProxy());
        $this->assertSame('192.168.1.1', $req3->getClientIp());
    }

    public function test_empty_and_whitespace_values_leave_proxies_unconfigured(): void
    {
        // Empty string
        $emptyReq = $this->dispatchThroughApp('', '10.0.0.1', '203.0.113.5');
        $this->assertFalse($emptyReq->isFromTrustedProxy());
        $this->assertSame('10.0.0.1', $emptyReq->getClientIp());

        // Whitespace only
        $spacesReq = $this->dispatchThroughApp('   ', '10.0.0.1', '203.0.113.5');
        $this->assertFalse($spacesReq->isFromTrustedProxy());
        $this->assertSame('10.0.0.1', $spacesReq->getClientIp());
    }

    public function test_unset_trusted_proxies_leaves_proxies_unconfigured(): void
    {
        $request = $this->dispatchThroughApp(null, '10.0.0.1', '203.0.113.5');
        $this->assertFalse($request->isFromTrustedProxy());
        $this->assertSame('10.0.0.1', $request->getClientIp());
    }

    public function test_single_element_wildcard_array_is_passed_as_string_to_trust_proxies(): void
    {
        // Single element array ['*']
        $reqStar = $this->dispatchThroughApp(['*'], '10.0.0.1', '203.0.113.50');
        $this->assertTrue($reqStar->isFromTrustedProxy());
        $this->assertSame('203.0.113.50', $reqStar->getClientIp());

        // Single element array ['**']
        $reqDoubleStar = $this->dispatchThroughApp(['**'], '10.0.0.1', '203.0.113.60');
        $this->assertTrue($reqDoubleStar->isFromTrustedProxy());
        $this->assertSame('203.0.113.60', $reqDoubleStar->getClientIp());
    }

    public function test_non_wildcard_array_config_preserves_specific_ips(): void
    {
        $arrayConfig = ['10.0.0.1', '10.0.0.2'];

        $matchingReq = $this->dispatchThroughApp($arrayConfig, '10.0.0.2', '203.0.113.70');
        $this->assertTrue($matchingReq->isFromTrustedProxy());
        $this->assertSame('203.0.113.70', $matchingReq->getClientIp());

        $untrustedReq = $this->dispatchThroughApp($arrayConfig, '10.0.0.3', '203.0.113.70');
        $this->assertFalse($untrustedReq->isFromTrustedProxy());
        $this->assertSame('10.0.0.3', $untrustedReq->getClientIp());
    }

    public function test_https_scheme_is_detected_from_forwarded_proto_when_proxy_trusted(): void
    {
        $request = $this->dispatchThroughApp('*', '10.0.0.1', '203.0.113.5', 'https');
        $this->assertTrue($request->isSecure());
        $this->assertSame('https', $request->getScheme());
    }

    public function test_forwarded_headers_ignored_when_proxy_untrusted(): void
    {
        // Untrusted caller sending spoofed X-Forwarded headers
        $request = $this->dispatchThroughApp(null, '198.51.100.99', '127.0.0.1', 'https');

        $this->assertFalse($request->isFromTrustedProxy());
        $this->assertSame('198.51.100.99', $request->getClientIp(), 'Should not trust spoofed X-Forwarded-For IP');
        $this->assertFalse($request->isSecure(), 'Should not trust spoofed X-Forwarded-Proto header');
        $this->assertSame('http', $request->getScheme());
    }
}

