<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        app()->booted(function () use ($middleware) {
            $trustedProxies = config('app.trusted_proxies');

            if (is_string($trustedProxies)) {
                $trimmed = trim($trustedProxies);
                if ($trimmed === '*' || $trimmed === '**') {
                    $middleware->trustProxies(at: $trimmed);
                } elseif ($trimmed !== '') {
                    $ips = array_values(array_filter(array_map('trim', explode(',', $trimmed)), fn ($ip) => $ip !== ''));
                    if (! empty($ips)) {
                        $middleware->trustProxies(at: $ips);
                    }
                }
            } elseif (is_array($trustedProxies) && ! empty($trustedProxies)) {
                $first = is_string(reset($trustedProxies)) ? trim(reset($trustedProxies)) : null;
                if (count($trustedProxies) === 1 && ($first === '*' || $first === '**')) {
                    $middleware->trustProxies(at: $first);
                } else {
                    $middleware->trustProxies(at: $trustedProxies);
                }
            }
        });

        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'password.change' => \App\Http\Middleware\ForcePasswordChange::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
