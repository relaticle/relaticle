<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Email;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;
use Relaticle\ActivityLog\Timeline\Sources\RelatedModelSource;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;

it('merges sources and sorts by date desc by default', function (): void {
    $person = Person::factory()->create();
    $email = Email::factory()->for($person)->create([
        'sent_at' => CarbonImmutable::parse('2026-04-17T10:00:00Z'),
    ]);

    $entries = TimelineBuilder::make($person)
        ->fromActivityLog()
        ->fromRelation('emails', fn (RelatedModelSource $s): RelatedModelSource => $s->event('sent_at', 'email_sent'))
        ->get();

    expect($entries->count())->toBeGreaterThanOrEqual(2);
    expect($entries->first()->occurredAt->getTimestamp())
        ->toBeGreaterThanOrEqual($entries->last()->occurredAt->getTimestamp());
});

it('applies between() filter', function (): void {
    $person = Person::factory()->create();
    Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::parse('2026-04-10')]);
    Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::parse('2026-04-20')]);

    $entries = TimelineBuilder::make($person)
        ->fromRelation('emails', fn (RelatedModelSource $s): RelatedModelSource => $s->event('sent_at', 'email_sent'))
        ->between(CarbonImmutable::parse('2026-04-15'), CarbonImmutable::parse('2026-04-25'))
        ->get();

    expect($entries)->toHaveCount(1);
});

it('applies ofEvent and exceptEvent filters after merge', function (): void {
    $person = Person::factory()->create();
    Email::factory()->for($person)->create([
        'sent_at' => CarbonImmutable::now(),
        'received_at' => CarbonImmutable::now(),
    ]);

    $entries = TimelineBuilder::make($person)
        ->fromRelation('emails', fn (RelatedModelSource $s): RelatedModelSource => $s->event('sent_at', 'email_sent')->event('received_at', 'email_received'))
        ->ofEvent(['email_sent'])
        ->get();

    expect($entries->pluck('event')->unique()->values()->all())->toBe(['email_sent']);
});

it('sortByDateAsc() reverses order', function (): void {
    $person = Person::factory()->create();
    Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::parse('2026-04-10')]);
    Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::parse('2026-04-20')]);

    $entries = TimelineBuilder::make($person)
        ->fromRelation('emails', fn (RelatedModelSource $s): RelatedModelSource => $s->event('sent_at', 'email_sent'))
        ->sortByDateAsc()
        ->get();

    expect($entries->first()->occurredAt->toDateString())->toBe('2026-04-10');
});
