<?php

declare(strict_types=1);

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
