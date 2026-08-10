<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->withVite();
});

it('public pages have no javascript errors', function (): void {
    $this->visit('/')
        ->assertNoJavaScriptErrors();
});

// Shiki highlights code by shelling out to node against node_modules/shiki, so
// this lives in the browser suite — the only CI job with the JS toolchain
// installed. The Feature-level documentation tests run with highlighting off.
it('highlights fenced code blocks on documentation pages', function (): void {
    $this->visit('/docs/developer')
        ->assertPresent('pre.shiki');
});
