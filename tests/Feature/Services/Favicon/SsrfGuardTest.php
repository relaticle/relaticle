<?php

declare(strict_types=1);

use App\Exceptions\SsrfGuardException;
use App\Services\Favicon\SsrfGuard;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;

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

test('pinned client refuses plain http', function (): void {
    expect(fn (): PendingRequest => SsrfGuard::pinnedClient('http://example.com/file.pdf'))
        ->toThrow(SsrfGuardException::class, 'Only https URLs on port 443 are allowed');
});

test('pinned client refuses a non-default port', function (): void {
    expect(fn (): PendingRequest => SsrfGuard::pinnedClient('https://example.com:8443/file.pdf'))
        ->toThrow(SsrfGuardException::class, 'Only https URLs on port 443 are allowed');
});

test('pinned client refuses a private host', function (): void {
    expect(fn (): PendingRequest => SsrfGuard::pinnedClient('https://10.0.0.1/file.pdf'))
        ->toThrow(SsrfGuardException::class);
});

test('pinned client never follows redirects and pins the resolved address', function (): void {
    $client = SsrfGuard::pinnedClient('https://93.184.216.34/file.pdf');
    $options = (fn (): array => $this->options)->call($client);

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['timeout'])->toBe(30)
        ->and($options['curl'][CURLOPT_RESOLVE])->toBe(['93.184.216.34:443:93.184.216.34']);
});

test('pinned client resolves a hostname once and pins the validated address', function (): void {
    $calls = 0;
    resolveHostsTo(['93.184.216.34'], $calls);

    $client = SsrfGuard::pinnedClient('https://cdn.example.com/brief.pdf');
    $options = (fn (): array => $this->options)->call($client);

    expect($options['curl'][CURLOPT_RESOLVE])->toBe(['cdn.example.com:443:93.184.216.34'])
        ->and($calls)->toBe(1);
});

test('pinned client refuses a hostname that resolves to a private address', function (): void {
    resolveHostsTo(['169.254.169.254']);

    expect(fn (): PendingRequest => SsrfGuard::pinnedClient('https://cdn.example.com/brief.pdf'))
        ->toThrow(SsrfGuardException::class);
});

test('pinned client refuses a hostname that fails to resolve', function (): void {
    resolveHostsTo([]);

    expect(fn (): PendingRequest => SsrfGuard::pinnedClient('https://cdn.example.com/brief.pdf'))
        ->toThrow(SsrfGuardException::class);
});
