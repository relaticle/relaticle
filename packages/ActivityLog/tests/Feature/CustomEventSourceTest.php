<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;
use Relaticle\ActivityLog\Timeline\Sources\CustomEventSource;
use Relaticle\ActivityLog\Timeline\TimelineEntry;
use Relaticle\ActivityLog\Timeline\Window;

it('yields entries from the closure', function (): void {
    $person = Person::factory()->create();

    $source = new CustomEventSource(priority: 30, resolver: function (Person $subject): iterable {
        yield new TimelineEntry(
            id: 'custom:1',
            type: 'custom',
            event: 'imported',
            occurredAt: CarbonImmutable::parse('2026-04-17T08:00:00Z'),
            dedupKey: 'custom:1',
            sourcePriority: 30,
            subject: $subject,
        );
    });

    $entries = collect($source->resolve($person, new Window(cap: 10)));

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->event)->toBe('imported');
});

it('throws when closure yields non-TimelineEntry', function (): void {
    $person = Person::factory()->create();

    $source = new CustomEventSource(priority: 30, resolver: function () {
        yield 'not an entry';
    });

    expect(fn () => iterator_to_array($source->resolve($person, new Window(cap: 10))))
        ->toThrow(TypeError::class);
});
