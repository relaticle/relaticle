<?php

declare(strict_types=1);

use App\Models\Task;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;

mutates(AppServiceProvider::class);

it('builds every application date on the immutable class', function (): void {
    expect(Date::now())->toBeInstanceOf(CarbonImmutable::class)
        ->and(now())->toBeInstanceOf(CarbonImmutable::class)
        ->and(today())->toBeInstanceOf(CarbonImmutable::class)
        ->and(Date::parse('2026-05-09'))->toBeInstanceOf(CarbonImmutable::class);
});

it('leaves a date untouched when a mutating method is called on it', function (): void {
    $instant = Date::parse('2026-05-09 08:00:00', 'UTC');

    $instant->addDay();
    $instant->startOfMonth();

    expect($instant->toDateTimeString())->toBe('2026-05-09 08:00:00');
});

it('casts model timestamps to the immutable class', function (): void {
    $task = new Task;
    $task->setRawAttributes(['created_at' => '2026-05-09 08:00:00'], true);

    expect($task->created_at)->toBeInstanceOf(CarbonImmutable::class);

    $task->created_at->addDay();

    expect($task->created_at->toDateTimeString())->toBe('2026-05-09 08:00:00');
});
