<?php

declare(strict_types=1);

it('uses the configured per-document description as the meta description', function (): void {
    $html = $this->get('/developers/self-hosting')->assertOk()->getContent();

    preg_match('/<meta name="description" content="([^"]*)"/', $html, $meta);

    expect($meta[1] ?? null)->toBe('Get Docker Compose, Coolify and Dokploy deployment steps for Relaticle, with PostgreSQL, Redis and Ollama setup.');
});

it('titles the documentation index with the brand-suffix convention', function (): void {
    $this->get('/developers')
        ->assertOk()
        ->assertSee('<title>Developer Documentation - Relaticle</title>', false);
});
