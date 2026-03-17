<?php

declare(strict_types=1);

it('public pages have no javascript errors', function (): void {
    $this->visit('/')
        ->assertNoJavaScriptErrors();
});
