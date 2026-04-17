<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Email;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

it('constructs with required fields only', function (): void {
    $entry = new TimelineEntry(
        id: 'src:1:created',
        type: 'activity_log',
        event: 'created',
        occurredAt: CarbonImmutable::parse('2026-04-17T10:00:00Z'),
        dedupKey: 'email:1:2026-04-17T10:00:00',
        sourcePriority: 10,
    );

    expect($entry->id)->toBe('src:1:created')
        ->and($entry->type)->toBe('activity_log')
        ->and($entry->event)->toBe('created')
        ->and($entry->occurredAt->toIso8601ZuluString())->toBe('2026-04-17T10:00:00Z')
        ->and($entry->dedupKey)->toBe('email:1:2026-04-17T10:00:00')
        ->and($entry->sourcePriority)->toBe(10)
        ->and($entry->subject)->toBeNull()
        ->and($entry->properties)->toBe([]);
});

it('carries subject, causer, relatedModel, and properties', function (): void {
    $person = Person::factory()->create();
    $email = Email::factory()->for($person)->create();

    $entry = new TimelineEntry(
        id: 'src:1:email_sent',
        type: 'related_model',
        event: 'email_sent',
        occurredAt: CarbonImmutable::now(),
        dedupKey: 'Email:1:now',
        sourcePriority: 20,
        subject: $person,
        relatedModel: $email,
        title: 'Email sent',
        properties: ['subject_line' => 'Hi'],
    );

    expect($entry->subject?->is($person))->toBeTrue()
        ->and($entry->relatedModel?->is($email))->toBeTrue()
        ->and($entry->title)->toBe('Email sent')
        ->and($entry->properties)->toBe(['subject_line' => 'Hi']);
});

it('is immutable (readonly)', function (): void {
    $entry = new TimelineEntry(
        id: 'x', type: 't', event: 'e',
        occurredAt: CarbonImmutable::now(),
        dedupKey: 'k', sourcePriority: 0,
    );

    /** @phpstan-ignore-next-line */
    expect(fn () => $entry->id = 'y')->toThrow(Error::class);
});
