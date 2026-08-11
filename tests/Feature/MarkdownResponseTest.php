<?php

declare(strict_types=1);

it('serves docs pages as clean article markdown without site chrome', function (): void {
    $markdown = $this->get('/docs/getting-started', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('Getting Started')
        ->and($markdown)->not->toContain('Sign In')
        ->and($markdown)->not->toContain('[ Start for free ]')
        ->and($markdown)->not->toContain('Skip to main content')
        ->and($markdown)->not->toContain('All rights reserved')
        ->and($markdown)->not->toContain('Developer Guide')
        ->and($markdown)->not->toContain('On this page');
});
