<?php

declare(strict_types=1);

beforeEach(function (): void {
    $this->withVite();
});

it('public pages have no javascript errors', function (): void {
    $this->visit('/')
        ->assertNoJavaScriptErrors();
});
