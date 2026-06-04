<?php

declare(strict_types=1);

use App\Exceptions\SsrfGuardException;
use App\Services\Favicon\SsrfGuard;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;

mutates(SsrfGuard::class);

test('rejects loopback addresses', function (): void {
    expect(function (): void {
        SsrfGuard::assertPublicHost('http://127.0.0.1/');
    })->toThrow(SsrfGuardException::class);
});

test('rejects private RFC1918 addresses', function (): void {
    expect(function (): void {
        SsrfGuard::assertPublicHost('http://10.0.0.1/');
    })->toThrow(SsrfGuardException::class);

    expect(function (): void {
        SsrfGuard::assertPublicHost('http://192.168.1.1/');
    })->toThrow(SsrfGuardException::class);

    expect(function (): void {
        SsrfGuard::assertPublicHost('http://172.16.0.1/');
    })->toThrow(SsrfGuardException::class);
});

test('rejects link-local cloud metadata address', function (): void {
    expect(function (): void {
        SsrfGuard::assertPublicHost('http://169.254.169.254/latest/meta-data/');
    })->toThrow(SsrfGuardException::class);
});

test('rejects ipv6 loopback and link-local', function (): void {
    expect(function (): void {
        SsrfGuard::assertPublicHost('http://[::1]/');
    })->toThrow(SsrfGuardException::class);

    expect(function (): void {
        SsrfGuard::assertPublicHost('http://[fe80::1]/');
    })->toThrow(SsrfGuardException::class);
});

test('rejects hostnames that resolve to private addresses', function (): void {
    // localhost resolves to 127.0.0.1 on every OS we run on.
    expect(function (): void {
        SsrfGuard::assertPublicHost('http://localhost/');
    })->toThrow(SsrfGuardException::class);
});

test('accepts a clearly-public address literal', function (): void {
    SsrfGuard::assertPublicHost('http://1.1.1.1/');
    expect(true)->toBeTrue();
});

test('redirect guard blocks redirects to non-public hosts but allows public ones', function (): void {
    $onRedirect = SsrfGuard::redirectGuardOptions()['allow_redirects']['on_redirect'];

    $request = new Request('GET', 'https://1.1.1.1');
    $response = new Response(302);

    expect(fn () => $onRedirect($request, $response, Utils::uriFor('http://169.254.169.254/latest/meta-data/')))
        ->toThrow(SsrfGuardException::class)
        ->and(fn () => $onRedirect($request, $response, Utils::uriFor('http://10.0.0.1/')))
        ->toThrow(SsrfGuardException::class)
        ->and(fn () => $onRedirect($request, $response, Utils::uriFor('http://1.1.1.1/')))
        ->not->toThrow(SsrfGuardException::class);
});
