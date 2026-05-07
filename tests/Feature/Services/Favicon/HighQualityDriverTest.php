<?php

declare(strict_types=1);

use App\Services\Favicon\Drivers\HighQualityDriver;
use Illuminate\Support\Facades\Http;

mutates(HighQualityDriver::class);

test('captures favicon-512x512 from minified html where href comes before sizes', function (): void {
    $html = '<link href="/css/a.css?v=1" rel="stylesheet" />'
        .'<link href="/css/b.css" rel="stylesheet" />'
        .'<link href="/favicon-512x512.png" sizes="512x512" rel="icon" />';

    Http::fake([
        'https://1.1.1.1' => Http::response($html, 200),
        'https://1.1.1.1/favicon-512x512.png' => Http::response('', 200),
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
        'https://1.1.1.1/favicon-512x512.png' => Http::response('', 200),
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
        'https://1.1.1.1/icon.png' => Http::response('', 200),
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
        'https://1.1.1.1/apple.png' => Http::response('', 200),
    ]);

    $driver = new HighQualityDriver;
    $favicon = $driver->fetch('https://1.1.1.1');

    expect($favicon->getFaviconUrl())->toBe('https://1.1.1.1/apple.png');
});

test('apple-touch-icon pattern is not fooled by data-href attribute', function (): void {
    $html = '<link rel="apple-touch-icon" data-href="/wrong.png" href="/correct.png" />';

    Http::fake([
        'https://1.1.1.1' => Http::response($html, 200),
        'https://1.1.1.1/correct.png' => Http::response('', 200),
    ]);

    $driver = new HighQualityDriver;
    $favicon = $driver->fetch('https://1.1.1.1');

    expect($favicon->getFaviconUrl())->toBe('https://1.1.1.1/correct.png');
});

test('apple-touch-icon pattern matches when href comes before rel', function (): void {
    $html = '<link href="/apple.png" rel="apple-touch-icon" />';

    Http::fake([
        'https://1.1.1.1' => Http::response($html, 200),
        'https://1.1.1.1/apple.png' => Http::response('', 200),
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
        'https://1.1.1.1/icon-512.png' => Http::response('', 200),
        'https://1.1.1.1/icon-256.png' => Http::response('', 200),
    ]);

    $driver = new HighQualityDriver;
    $favicon = $driver->fetch('https://1.1.1.1');

    expect($favicon->getFaviconUrl())->toBe('https://1.1.1.1/icon-512.png');
});

test('refuses to fetch from private addresses', function (): void {
    $driver = new HighQualityDriver;

    expect($driver->fetch('http://127.0.0.1/'))->toBeNull()
        ->and($driver->fetch('http://10.0.0.1/'))->toBeNull()
        ->and($driver->fetch('http://169.254.169.254/'))->toBeNull();
});
