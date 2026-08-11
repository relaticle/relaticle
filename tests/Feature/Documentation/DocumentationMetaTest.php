<?php

declare(strict_types=1);

it('uses the configured per-document description as the meta description', function (): void {
    $html = $this->get('/docs/getting-started')->assertOk()->getContent();

    preg_match('/<meta name="description" content="([^"]*)"/', $html, $meta);

    expect($meta[1] ?? null)->toBe('Set up your account and learn the basics.');
});

it('titles the documentation index with the brand-suffix convention', function (): void {
    $this->get('/docs')
        ->assertOk()
        ->assertSee('<title>Documentation - Relaticle</title>', false);
});
