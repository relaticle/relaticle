<?php

declare(strict_types=1);

it('uses the configured per-document description as the meta description', function (): void {
    $html = $this->get('/developers/self-hosting')->assertOk()->getContent();

    preg_match('/<meta name="description" content="([^"]*)"/', $html, $meta);

    expect($meta[1] ?? null)->toBe('Get Docker Compose, Coolify, Dokploy and Laravel Cloud deployment steps for Relaticle, with PostgreSQL, Redis and Ollama setup.');
});

it('titles the documentation index with the brand-suffix convention', function (): void {
    $this->get('/developers')
        ->assertOk()
        ->assertSee('<title>Developer Documentation - Relaticle</title>', false);
});

it('keeps the title, description and heading of :path within search-result bounds', function (string $path): void {
    $html = $this->get($path)->assertOk()->getContent();

    preg_match('/<title>(.*?)<\/title>/s', $html, $title);
    preg_match('/<meta name="description" content="([^"]*)"/', $html, $description);

    $titleText = html_entity_decode(trim($title[1] ?? ''));
    $descriptionText = html_entity_decode($description[1] ?? '');

    expect(mb_strlen($titleText))->toBeLessThanOrEqual(60, "{$path} title: {$titleText}")
        ->and(mb_strlen($titleText))->toBeGreaterThanOrEqual(20, "{$path} title: {$titleText}")
        ->and(mb_strlen($descriptionText))->toBeLessThanOrEqual(160, "{$path} description: {$descriptionText}")
        ->and(mb_strlen($descriptionText))->toBeGreaterThanOrEqual(70, "{$path} description: {$descriptionText}")
        ->and($titleText.$descriptionText)->not->toContain("\u{2014}")
        ->and(substr_count($html, '<h1'))->toBe(1, "{$path} must render exactly one h1");
})->with(['/help', '/developers']);
