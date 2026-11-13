<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    Route::get('/_trust-proxy-probe', fn (Request $request): array => [
        'isSecure' => $request->isSecure(),
        'scheme' => $request->getScheme(),
        'host' => $request->getHost(),
        'clientIp' => $request->getClientIp(),
    ]);
});

it('honours the forwarded proto and client ip from a private network proxy but never its forwarded host', function (): void {
    $this->call(
        method: 'GET',
        uri: '/_trust-proxy-probe',
        server: [
            'REMOTE_ADDR' => '172.18.0.2',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'crm.example.com',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.5',
        ],
    )->assertOk()->assertJson([
        'isSecure' => true,
        'scheme' => 'https',
        'host' => 'relaticle.test',
        'clientIp' => '203.0.113.5',
    ]);
});

it('refuses to honour forwarded headers from a public IP so attackers cannot spoof X-Forwarded-* directly against the FPM port', function (): void {
    $this->call(
        method: 'GET',
        uri: 'http://crm.example.com/_trust-proxy-probe',
        server: [
            'REMOTE_ADDR' => '203.0.113.99',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'evil.example.com',
            'HTTP_X_FORWARDED_FOR' => '10.0.0.1',
        ],
    )->assertOk()->assertJson([
        'isSecure' => false,
        'scheme' => 'http',
        'host' => 'crm.example.com',
        'clientIp' => '203.0.113.99',
    ]);
});

it('honours forwarded headers from loopback so reverse proxies on the host work', function (): void {
    $this->call(
        method: 'GET',
        uri: '/_trust-proxy-probe',
        server: [
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ],
    )->assertOk()->assertJson([
        'isSecure' => true,
    ]);
});

it('emits https asset URLs when the proxy forwards X-Forwarded-Proto: https, the actual mechanism behind the unstyled-CSS bug', function (): void {
    Route::get('/_vite-asset-probe', fn (): array => [
        'asset' => asset('build/app.css'),
        'scheme' => request()->getScheme(),
    ]);

    $response = $this->call(
        method: 'GET',
        uri: '/_vite-asset-probe',
        server: [
            'REMOTE_ADDR' => '172.18.0.2',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'crm.example.com',
        ],
    )->assertOk();

    expect($response->json('scheme'))->toBe('https');
    expect($response->json('asset'))->toStartWith('https://');
});

it('emits http asset URLs when forwarded headers come from an untrusted public IP, confirming this would cause the unstyled-CSS bug if trust were misconfigured', function (): void {
    Route::get('/_vite-asset-probe', fn (): array => [
        'asset' => asset('build/app.css'),
        'scheme' => request()->getScheme(),
    ]);

    $response = $this->call(
        method: 'GET',
        uri: '/_vite-asset-probe',
        server: [
            'REMOTE_ADDR' => '203.0.113.99',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ],
    )->assertOk();

    expect($response->json('scheme'))->toBe('http');
    expect($response->json('asset'))->toStartWith('http://');
});

it('trusts the Laravel Cloud edge, which forwards HTTPS from a public IPv6 address, only on Laravel Cloud', function (string|false $laravelCloud, string $scheme): void {
    $boot = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->bootstrapWith([
            Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
            Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
        ]);
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $proxyMiddleware = array_values(array_filter(
            $kernel->getGlobalMiddleware(),
            fn (string $middleware): bool => in_array($middleware, [
                App\Http\Middleware\ConfigureTrustedProxies::class,
                Illuminate\Http\Middleware\TrustProxies::class,
            ], true),
        ));
        $request = Illuminate\Http\Request::create('http://relaticle.laravel.cloud/', server: [
            'REMOTE_ADDR' => '2600:1f18:124c:a910:b364::4',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        echo (new Illuminate\Pipeline\Pipeline($app))
            ->send($request)
            ->through($proxyMiddleware)
            ->then(fn (Illuminate\Http\Request $request): Illuminate\Http\Response => new Illuminate\Http\Response($request->getScheme()))
            ->getContent();
        PHP;

    $process = new Process([PHP_BINARY, '-r', $boot], base_path(), ['LARAVEL_CLOUD' => $laravelCloud]);

    $process->mustRun();

    expect($process->getOutput())->toBe($scheme);
})->with([
    'on Laravel Cloud' => ['1', 'https'],
    'off Laravel Cloud' => [false, 'http'],
]);
