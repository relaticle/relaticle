<?php

declare(strict_types=1);

use App\Exceptions\SsrfGuardException;
use App\Exceptions\UploadException;
use App\Support\Http\HostResolver;
use App\Support\Http\SsrfGuard;
use App\Support\Media\UploadAllowlist;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;

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

test('rejects shared, translated and reserved ranges filter_var calls public', function (string $address): void {
    expect(function () use ($address): void {
        SsrfGuard::assertPublicHost("http://{$address}/");
    })->toThrow(SsrfGuardException::class);
})->with([
    'carrier-grade nat' => ['100.64.0.1'],
    'nat64 mapping loopback' => ['[64:ff9b::7f00:1]'],
    '6to4 embedding loopback' => ['[2002:7f00:1::]'],
    'ietf protocol assignments' => ['192.0.0.1'],
    'benchmarking' => ['198.18.0.1'],
    '6to4 relay anycast' => ['192.88.99.1'],
    'multicast' => ['224.0.0.1'],
    'teredo embedding loopback' => ['[2001::7f00:1]'],
    'local-use nat64' => ['[64:ff9b:1::7f00:1]'],
    'ipv4-compatible loopback' => ['[::7f00:1]'],
    'ipv4-compatible metadata' => ['[::a9fe:a9fe]'],
    'deprecated site-local' => ['[fec0::1]'],
    'orchidv2' => ['[2001:20::1]'],
]);

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

test('pinned client never follows redirects and leaves an address literal unpinned', function (): void {
    $client = SsrfGuard::pinnedClient('https://93.184.216.34/file.pdf');
    $options = (fn (): array => $this->options)->call($client);

    expect($options['allow_redirects'])->toBeFalse()
        ->and($options['decode_content'])->toBeFalse()
        ->and($options['timeout'])->toBe(30)
        ->and($options['curl'])->toBe([]);
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

test('guard pins every hop to the address it validated', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (string $host): array => match ($host) {
        'favicon.example.com' => ['93.184.216.34'],
        'cdn.example.com' => ['1.1.1.1'],
        default => [],
    }));

    $pins = [];

    Http::fake(function (HttpClientRequest $request, array $options) use (&$pins) {
        $pins[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return str_contains($request->url(), 'favicon.example.com')
            ? Http::response('', 302, ['Location' => 'http://cdn.example.com:8080/icon.png'])
            : Http::response('png');
    });

    SsrfGuard::guard(Http::timeout(5))->get('https://favicon.example.com/icon.png');

    expect($pins)->toBe([
        ['favicon.example.com:443:93.184.216.34'],
        ['cdn.example.com:8080:1.1.1.1'],
    ]);
});

test('guard refuses a host that resolves to a private address at send time', function (): void {
    $lookups = 0;

    app()->instance(HostResolver::class, new HostResolver(function () use (&$lookups): array {
        $lookups++;

        return $lookups === 1 ? ['93.184.216.34'] : ['169.254.169.254'];
    }));

    Http::fake(['*' => Http::response('secret')]);

    expect(SsrfGuard::isAllowed('https://rebind.example.com/icon.png'))->toBeTrue()
        ->and(fn () => SsrfGuard::guard(Http::timeout(5))->get('https://rebind.example.com/icon.png'))
        ->toThrow(SsrfGuardException::class);

    Http::assertNothingSent();
});

test('guard aborts a download larger than the upload limit', function (): void {
    $options = (fn (): array => $this->options)->call(SsrfGuard::guard(Http::timeout(5)));

    expect(fn () => $options['progress'](UploadAllowlist::maxBytes() + 1, 0))
        ->toThrow(UploadException::class)
        ->and(fn () => $options['progress'](0, UploadAllowlist::maxBytes() + 1))
        ->toThrow(UploadException::class);
});

test('guard pins every validated address and leaves an address literal unpinned', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (string $host): array => match ($host) {
        'cdn.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
        default => [$host],
    }));

    $pins = [];

    Http::fake(function (HttpClientRequest $request, array $options) use (&$pins) {
        $pins[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return Http::response('png');
    });

    SsrfGuard::guard(Http::timeout(5))->get('https://cdn.example.com/icon.png');
    SsrfGuard::guard(Http::timeout(5))->get('https://[2606:4700:4700::1111]/icon.png');

    expect($pins)->toBe([
        ['cdn.example.com:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]'],
        null,
    ]);
});
