<?php

declare(strict_types=1);

use Illuminate\Support\Str;

it('registers every class listener once per event', function (): void {
    $duplicates = collect(app('events')->getRawListeners())
        ->map(fn (array $listeners): array => collect($listeners)
            ->filter(fn (mixed $listener): bool => is_string($listener))
            ->map(fn (string $listener): string => Str::before($listener, '@'))
            ->duplicates()
            ->values()
            ->all())
        ->filter();

    expect($duplicates->all())->toBe([]);
});
