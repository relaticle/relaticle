<?php

declare(strict_types=1);

it('dashboard input enforces the 5000-char client limit on the send button', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/filament/pages/dashboard.blade.php');

    expect($contents)->toContain('text.length > 5000');
});

it('dashboard input renders a character counter at >4000 chars', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/filament/pages/dashboard.blade.php');

    expect($contents)->toContain('text.length > 4000');
});
