<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;
use Relaticle\ActivityLog\Timeline\Sources\ActivityLogSource;
use Relaticle\ActivityLog\Timeline\Window;

it('returns entries for the subject\'s own activity log', function (): void {
    $person = Person::factory()->create();
    $person->update(['name' => 'Renamed']);

    $source = new ActivityLogSource(priority: 10);
    $entries = collect($source->resolve($person, new Window(cap: 10)));

    expect($entries)->toHaveCount(2);
    expect($entries->pluck('type')->unique()->values()->all())->toBe(['activity_log']);
    expect($entries->pluck('event')->sort()->values()->all())->toBe(['created', 'updated']);
});

it('honors the date window', function (): void {
    $person = Person::factory()->create();
    $person->update(['name' => 'Later']);

    $from = CarbonImmutable::now()->addDay();
    $to = CarbonImmutable::now()->addDays(2);

    $source = new ActivityLogSource(priority: 10);
    $entries = collect($source->resolve($person, new Window(from: $from, to: $to, cap: 10)));

    expect($entries)->toBeEmpty();
});

it('caps the number of entries returned', function (): void {
    $person = Person::factory()->create();
    for ($i = 0; $i < 5; $i++) {
        $person->update(['name' => "Name {$i}"]);
    }

    $source = new ActivityLogSource(priority: 10);
    $entries = collect($source->resolve($person, new Window(cap: 3)));

    expect($entries)->toHaveCount(3);
});

it('produces a dedup key scoped by subject and second-precision timestamp', function (): void {
    $person = Person::factory()->create();
    $source = new ActivityLogSource(priority: 10);

    $entry = collect($source->resolve($person, new Window(cap: 10)))->first();

    expect($entry->dedupKey)->toStartWith($person::class.':'.$person->getKey().':')
        ->and($entry->sourcePriority)->toBe(10)
        ->and($entry->type)->toBe('activity_log');
});
