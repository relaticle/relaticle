<?php

declare(strict_types=1);

use App\Services\Favicon\Drivers\HighQualityDriver;
use App\Support\Http\HostResolver;
use AshAllenDesign\FaviconFetcher\Exceptions\ConnectionException;
use AshAllenDesign\FaviconFetcher\Favicon;
use Illuminate\Http\Client\ConnectionException as HttpConnectionException;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Http;

mutates(HighQualityDriver::class);

test('captures favicon-512x512 from minified html where href comes before sizes', function (): void {
    $html = '<link href="/css/a.css?v=1" rel="stylesheet" />'
        .'<link href="/css/b.css" rel="stylesheet" />'
        .'<link href="/favicon-512x512.png" sizes="512x512" rel="icon" />';

    Http::fake([
        'https://1.1.1.1' => Http::response($html, 200),
        'https://1.1.1.1/favicon-512x512.png' => Http::response(onePixelPng()),
    ]);

    $driver = new HighQualityDriver;
    $favicon = $driver->fetch('https://1.1.1.1');

    expect($favicon)->not->toBeNull()
        ->and($favicon->getFaviconUrl())->toBe('https://1.1.1.1/favicon-512x512.png');
});

test('captures favicon-512x512 when sizes comes before href', function (): void {
    $html = '<link rel="icon" sizes="512x512" href="/favicon-512x512.png" />';

    Http::fake([
        'https://1.1.1.1' => Http::response($html, 200),
        'https://1.1.1.1/favicon-512x512.png' => Http::response(onePixelPng()),
    ]);

    $driver = new HighQualityDriver;
    $favicon = $driver->fetch('https://1.1.1.1');

    expect($favicon->getFaviconUrl())->toBe('https://1.1.1.1/favicon-512x512.png');
});

test('does not span across multiple link tags when matching sizes', function (): void {
    $html = '<link href="/decoy-1.css" rel="stylesheet" />'
        .'<link href="/decoy-2.css" rel="stylesheet" />'
        .'<link href="/icon.png" sizes="256x256" rel="icon" />';

    Http::fake([
        'https://1.1.1.1' => Http::response($html, 200),
        'https://1.1.1.1/icon.png' => Http::response(onePixelPng()),
    ]);

    $driver = new HighQualityDriver;
    $favicon = $driver->fetch('https://1.1.1.1');

    $faviconUrl = $favicon->getFaviconUrl();

    expect($faviconUrl)->toBe('https://1.1.1.1/icon.png');
    expect($faviconUrl)->not->toContain('decoy');
});

test('falls through to apple-touch-icon when no sized icon present', function (): void {
    $html = '<link rel="apple-touch-icon" href="/apple.png" />';

    Http::fake([
        'https://1.1.1.1' => Http::response($html, 200),
        'https://1.1.1.1/apple.png' => Http::response(onePixelPng()),
    ]);

    $driver = new HighQualityDriver;
    $favicon = $driver->fetch('https://1.1.1.1');

    expect($favicon->getFaviconUrl())->toBe('https://1.1.1.1/apple.png');
});

test('apple-touch-icon pattern is not fooled by data-href attribute', function (): void {
    $html = '<link rel="apple-touch-icon" data-href="/wrong.png" href="/correct.png" />';

    Http::fake([
        'https://1.1.1.1' => Http::response($html, 200),
        'https://1.1.1.1/correct.png' => Http::response(onePixelPng()),
    ]);

    $driver = new HighQualityDriver;
    $favicon = $driver->fetch('https://1.1.1.1');

    expect($favicon->getFaviconUrl())->toBe('https://1.1.1.1/correct.png');
});

test('apple-touch-icon pattern matches when href comes before rel', function (): void {
    $html = '<link href="/apple.png" rel="apple-touch-icon" />';

    Http::fake([
        'https://1.1.1.1' => Http::response($html, 200),
        'https://1.1.1.1/apple.png' => Http::response(onePixelPng()),
    ]);

    $driver = new HighQualityDriver;
    $favicon = $driver->fetch('https://1.1.1.1');

    expect($favicon->getFaviconUrl())->toBe('https://1.1.1.1/apple.png');
});

test('high-res pattern prefers larger sizes when multiple are present', function (): void {
    $html = '<link href="/icon-256.png" sizes="256x256" rel="icon" />'
        .'<link href="/icon-512.png" sizes="512x512" rel="icon" />';

    Http::fake([
        'https://1.1.1.1' => Http::response($html, 200),
        'https://1.1.1.1/icon-512.png' => Http::response(onePixelPng()),
        'https://1.1.1.1/icon-256.png' => Http::response(onePixelPng()),
    ]);

    $driver = new HighQualityDriver;
    $favicon = $driver->fetch('https://1.1.1.1');

    expect($favicon->getFaviconUrl())->toBe('https://1.1.1.1/icon-512.png');
});

test('passes over a linked icon whose bytes the logo collection cannot store', function (string $body, string $contentType): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => ['93.184.216.34']));

    Http::fake([
        'https://1.1.1.1' => Http::response('<link rel="apple-touch-icon" href="/apple.png">', 200, ['Content-Type' => 'text/html']),
        'https://1.1.1.1/*' => Http::response($body, 200, ['Content-Type' => $contentType]),
        'https://www.google.com/*' => Http::response(onePixelPng(), 200, ['Content-Type' => 'image/png']),
    ]);

    expect((new HighQualityDriver)->fetch('https://1.1.1.1')?->getFaviconUrl())
        ->toBe('https://www.google.com/s2/favicons?sz=256&domain=1.1.1.1');
})->with([
    'html page' => ['<!DOCTYPE html><html><body>Not found</body></html>', 'text/html; charset=utf-8'],
    'html page declared as png' => ['<!DOCTYPE html><html><body>Not found</body></html>', 'image/png'],
    'json error' => ['{"error":"not found"}', 'application/json'],
    'svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
    'empty body' => ['', 'image/png'],
]);

test('accepts a linked icon by its bytes whatever type the server declares', function (string $contentType): void {
    Http::fake([
        'https://1.1.1.1' => Http::response('<link rel="apple-touch-icon" href="/apple.png">', 200, ['Content-Type' => 'text/html']),
        'https://1.1.1.1/apple.png' => Http::response(onePixelPng(), 200, ['Content-Type' => $contentType]),
    ]);

    expect((new HighQualityDriver)->fetch('https://1.1.1.1')?->getFaviconUrl())->toBe('https://1.1.1.1/apple.png');
})->with([
    'png' => ['image/png'],
    'misspelled type' => ['image/jpg'],
    'undeclared binary' => ['application/octet-stream'],
    'html' => ['text/html'],
]);

test('passes over a guessed apple touch icon the site answers with its own page', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => ['93.184.216.34']));

    Http::fake([
        'https://www.google.com/*' => Http::response(onePixelPng()),
        '*' => Http::response('<html><body>Home</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    expect((new HighQualityDriver)->fetch('https://1.1.1.1')?->getFaviconUrl())
        ->toBe('https://www.google.com/s2/favicons?sz=256&domain=1.1.1.1');
});

test('passes over a linked icon that redirects to a page', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => ['93.184.216.34']));

    Http::fake([
        'https://1.1.1.1' => Http::response('<link rel="apple-touch-icon" href="/apple.png">'),
        'https://1.1.1.1/apple.png' => Http::response('', 302, ['Location' => 'https://1.1.1.1/login']),
        'https://1.1.1.1/login' => Http::response('<html><body>Sign in</body></html>', 200, ['Content-Type' => 'text/html']),
        'https://www.google.com/*' => Http::response(onePixelPng()),
        '*' => Http::response('', 404),
    ]);

    expect((new HighQualityDriver)->fetch('https://1.1.1.1')?->getFaviconUrl())
        ->toBe('https://www.google.com/s2/favicons?sz=256&domain=1.1.1.1');
});

test('accepts a linked icon from a server that refuses head requests', function (): void {
    Http::fake(fn (HttpClientRequest $request) => match (true) {
        $request->method() === 'HEAD' => Http::response('', 405),
        $request->url() === 'https://1.1.1.1/apple.png' => Http::response(onePixelPng()),
        default => Http::response('<link rel="apple-touch-icon" href="/apple.png">'),
    });

    expect((new HighQualityDriver)->fetch('https://1.1.1.1')?->getFaviconUrl())->toBe('https://1.1.1.1/apple.png');
});

test('refuses to fetch from private addresses', function (): void {
    $driver = new HighQualityDriver;

    expect($driver->fetch('http://127.0.0.1/'))->toBeNull()
        ->and($driver->fetch('http://10.0.0.1/'))->toBeNull()
        ->and($driver->fetch('http://169.254.169.254/'))->toBeNull();
});

test('never follows a page redirect to a non-public host', function (): void {
    Http::fake([
        'https://1.1.1.1' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '*' => Http::response('<link rel="apple-touch-icon" href="/apple-touch-icon.png">'),
    ]);

    (new HighQualityDriver)->fetch('https://1.1.1.1');

    Http::assertNotSent(fn (HttpClientRequest $request): bool => str_contains($request->url(), '169.254.169.254'));
});

test('sends nothing when a host resolves to a private address at send time', function (): void {
    $lookups = 0;

    app()->instance(HostResolver::class, new HostResolver(function () use (&$lookups): array {
        $lookups++;

        return $lookups === 1 ? ['93.184.216.34'] : ['169.254.169.254'];
    }));

    Http::fake(['*' => Http::response('<link rel="apple-touch-icon" href="/apple-touch-icon.png">')]);

    expect((new HighQualityDriver)->fetch('https://rebind.example.com'))->toBeNull();

    Http::assertNothingSent();
});

test('reports a host that does not resolve as a connection failure', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => []));

    Http::fake();

    expect(fn (): ?Favicon => (new HighQualityDriver)->fetch('https://no-such-host.example.com'))
        ->toThrow(ConnectionException::class);

    Http::assertNothingSent();
});

test('reports an unreachable site with no icon from any other source as a connection failure', function (): void {
    Http::fake([
        'https://1.1.1.1' => Http::failedConnection(),
        '*' => Http::response('', 404),
    ]);

    expect(fn (): ?Favicon => (new HighQualityDriver)->fetch('https://1.1.1.1'))
        ->toThrow(ConnectionException::class);
});

test('uses another source when the site is unreachable', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => ['93.184.216.34']));

    Http::fake([
        'https://1.1.1.1' => Http::failedConnection(),
        'https://www.google.com/*' => Http::response(onePixelPng()),
        '*' => Http::response('', 404),
    ]);

    expect((new HighQualityDriver)->fetch('https://1.1.1.1')?->getFaviconUrl())
        ->toBe('https://www.google.com/s2/favicons?sz=256&domain=1.1.1.1');
});

test('finds no favicon when the site answers and no source has an icon', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    expect((new HighQualityDriver)->fetch('https://1.1.1.1'))->toBeNull();
});

test('keeps a path and query in the page url out of the google lookup', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => ['93.184.216.34']));

    Http::fake([
        'https://1.1.1.1/*' => Http::response('', 404),
        'https://www.google.com/*' => Http::response(onePixelPng()),
        '*' => Http::response('', 404),
    ]);

    $favicon = (new HighQualityDriver)->fetch('https://1.1.1.1/../?&x=1#frag');

    expect($favicon?->getFaviconUrl())->toBe('https://www.google.com/s2/favicons?sz=256&domain=1.1.1.1');

    Http::assertNotSent(fn (HttpClientRequest $request): bool => str_contains($request->url(), 'google.com') && str_contains($request->url(), 'x=1'));
});

test('keeps a path and query in the page url out of the duckduckgo lookup', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => ['93.184.216.34']));

    Http::fake([
        'https://icons.duckduckgo.com/*' => Http::response(onePixelPng()),
        '*' => Http::response('', 404),
    ]);

    $favicon = (new HighQualityDriver)->fetch('https://1.1.1.1/../a/b?x=1');

    expect($favicon?->getFaviconUrl())->toBe('https://icons.duckduckgo.com/ip3/1.1.1.1.ico');
});

test('skips the third party lookups when the host is not a bare hostname', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => ['93.184.216.34']));

    Http::fake(['*' => Http::response('', 404)]);

    expect((new HighQualityDriver)->fetch('https://[2606:4700:4700::1111]/'))->toBeNull();

    Http::assertNotSent(fn (HttpClientRequest $request): bool => str_contains($request->url(), 'google.com') || str_contains($request->url(), 'duckduckgo.com'));
});

test('keeps the third party lookups for a fully qualified host with a trailing dot', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => ['93.184.216.34']));

    Http::fake([
        'https://icons.duckduckgo.com/*' => Http::response(onePixelPng()),
        '*' => Http::response('', 404),
    ]);

    expect((new HighQualityDriver)->fetch('https://example.com.')?->getFaviconUrl())
        ->toBe('https://icons.duckduckgo.com/ip3/example.com.ico');
});

test('caps a request at the time left in the budget', function (): void {
    $this->freezeTime();

    $timeouts = [];

    Http::fake(function (HttpClientRequest $request, array $options) use (&$timeouts) {
        $timeouts[] = [$options['timeout'], $options['connect_timeout']];
        $this->travel(17)->seconds();

        return Http::response('', 404);
    });

    expect(fn (): ?Favicon => (new HighQualityDriver)->fetch('https://1.1.1.1'))->toThrow(ConnectionException::class)
        ->and($timeouts[0])->toEqual([5, 3])
        ->and($timeouts[1])->toEqual([3, 3]);
});

test('returns an icon found at the end of the budget without verifying it', function (): void {
    $this->freezeTime();

    $requested = [];

    Http::fake(function (HttpClientRequest $request) use (&$requested) {
        $requested[] = $request->method().' '.$request->url();
        $this->travel(19)->seconds();

        return Http::response('<link rel="apple-touch-icon" href="/touch.png">');
    });

    expect((new HighQualityDriver)->fetch('https://1.1.1.1')?->getFaviconUrl())->toBe('https://1.1.1.1/touch.png')
        ->and($requested)->toBe(['GET https://1.1.1.1']);
});

test('finds no favicon when the last source answers not found at the end of the budget', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => ['93.184.216.34']));
    $this->freezeTime();

    Http::fake(function (HttpClientRequest $request) {
        if (str_contains($request->url(), 'duckduckgo')) {
            $this->travel(20)->seconds();
        }

        return Http::response('', 404);
    });

    expect((new HighQualityDriver)->fetch('https://1.1.1.1'))->toBeNull();
});

test('treats a lookup cut off by the end of the budget as out of time, not as no favicon', function (): void {
    app()->instance(HostResolver::class, new HostResolver(fn (): array => ['93.184.216.34']));
    $this->freezeTime();

    Http::fake(function (HttpClientRequest $request) {
        if (str_contains($request->url(), 'duckduckgo')) {
            $this->travel(20)->seconds();

            throw new HttpConnectionException('cURL error 28: Operation timed out');
        }

        return Http::response('', 404);
    });

    expect(fn (): ?Favicon => (new HighQualityDriver)->fetch('https://1.1.1.1'))->toThrow(ConnectionException::class);
});
