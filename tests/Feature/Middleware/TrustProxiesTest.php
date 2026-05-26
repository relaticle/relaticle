<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::get('/_trust-proxy-probe', fn (Request $request): array => [
        'isSecure' => $request->isSecure(),
        'scheme' => $request->getScheme(),
        'host' => $request->getHost(),
        'clientIp' => $request->getClientIp(),
    ]);
});

it('honours forwarded headers from a private network proxy so HTTPS is detected behind Traefik', function (): void {
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
        'host' => 'crm.example.com',
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

it('emits https asset URLs when the proxy forwards X-Forwarded-Proto: https — the actual mechanism behind the unstyled-CSS bug', function (): void {
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

it('emits http asset URLs when forwarded headers come from an untrusted public IP — confirming this would cause the unstyled-CSS bug if trust were misconfigured', function (): void {
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
