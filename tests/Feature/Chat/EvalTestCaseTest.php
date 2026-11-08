<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Pest\Evals\Plugin;

it('keeps the shared stray request guard outside eval mode', function (): void {
    expect(Plugin::isEvalMode())->toBeFalse();

    Http::get('https://example.test/unfaked');
})->throws(RuntimeException::class, 'Attempted request');
