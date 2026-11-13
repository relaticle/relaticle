<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

function sessionCookieOn(string $uri): Cookie
{
    $cookies = test()->get($uri)->baseResponse->headers->getCookies();

    return collect($cookies)->firstWhere(fn (Cookie $cookie): bool => $cookie->getName() === config('session.cookie'));
}

function sessionSecureConfigFor(string $appUrl, string $override): ?bool
{
    $result = Process::path(base_path())
        ->env([
            'APP_URL' => $appUrl,
            'SESSION_SECURE_COOKIE' => $override,
            'APP_CONFIG_CACHE' => sys_get_temp_dir().'/'.Str::uuid().'-no-config-cache.php',
        ])
        ->run([PHP_BINARY, 'artisan', 'config:show', 'session.secure', '--no-ansi'])
        ->throw();

    return match (Str::afterLast(trim($result->output()), ' ')) {
        'true' => true,
        'false' => false,
        default => null,
    };
}

it('defaults the session cookie to secure when the app url is https', function (): void {
    expect(sessionSecureConfigFor('https://crm.example.com', ''))->toBeTrue();
});

it('defaults the session cookie to insecure when the app url is http', function (): void {
    expect(sessionSecureConfigFor('http://crm.example.com', ''))->toBeFalse();
});

it('lets SESSION_SECURE_COOKIE=false override an https app url', function (): void {
    expect(sessionSecureConfigFor('https://crm.example.com', 'false'))->toBeFalse();
});

it('lets SESSION_SECURE_COOKIE=true override an http app url', function (): void {
    expect(sessionSecureConfigFor('http://crm.example.com', 'true'))->toBeTrue();
});

it('sends the session cookie with Secure over plain http when configured secure', function (): void {
    config(['session.secure' => true]);

    expect(sessionCookieOn('/app/login')->isSecure())->toBeTrue();
});

it('sends the session cookie without Secure over plain http when configured insecure', function (): void {
    config(['session.secure' => false]);

    expect(sessionCookieOn('/app/login')->isSecure())->toBeFalse();
});
