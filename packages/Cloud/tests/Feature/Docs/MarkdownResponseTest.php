<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Relaticle\Cloud\Docs\Http\Controllers\DocumentationController;
use Relaticle\Cloud\Marketing\Http\Controllers\ComparisonController;
use Relaticle\Cloud\Marketing\Http\Controllers\HomeController;
use Relaticle\Cloud\Marketing\Http\Middleware\AddVaryAcceptHeader;

mutates(DocumentationController::class, HomeController::class, ComparisonController::class, AddVaryAcceptHeader::class);

it('serves docs pages as clean article markdown without site chrome', function (): void {
    $markdown = $this->get('/developers/self-hosting', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Quick Start')
        ->and($markdown)->toContain('Reverse Proxy and SSL')
        ->and($markdown)->not->toContain('Sign In')
        ->and($markdown)->not->toContain('[ Start for free ]')
        ->and($markdown)->not->toContain('Skip to content')
        ->and($markdown)->not->toContain('Searching help and developer docs')
        ->and($markdown)->not->toContain('On this page');
});

it('serves the homepage as markdown without nav chrome or alpine fragments', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['stargazers_count' => 42], 200),
    ]);

    $markdown = $this->get('/', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->not->toContain('mobileMenu')
        ->and($markdown)->not->toContain('Skip to main content')
        ->and(substr_count($markdown, 'Pricing'))->toBeLessThanOrEqual(1);
});

it('decodes html entities instead of leaking double-escaped ampersands', function (): void {
    Http::fake([
        'api.github.com/*' => Http::response(['stargazers_count' => 42], 200),
    ]);

    $markdown = $this->get('/press', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Press & brand resources')
        ->and($markdown)->not->toContain('Press &amp; brand resources');
});

it('declares Vary: Accept on both variants of a content-negotiated route', function (): void {
    $htmlVary = $this->get('/compare/relaticle-vs-twenty')
        ->assertOk()
        ->headers->get('Vary', '');

    $markdownVary = $this->get('/compare/relaticle-vs-twenty', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->headers->get('Vary', '');

    expect($htmlVary)->toContain('Accept')
        ->and($markdownVary)->toContain('Accept');
});
