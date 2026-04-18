<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Email;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;
use Relaticle\ActivityLog\Timeline\Sources\RelatedModelSource;
use Relaticle\ActivityLog\Timeline\Window;

it('emits one entry per configured date column', function (): void {
    $person = Person::factory()->create();
    Email::factory()->for($person)->create([
        'sent_at' => CarbonImmutable::parse('2026-04-17T09:00:00Z'),
        'received_at' => CarbonImmutable::parse('2026-04-17T10:00:00Z'),
    ]);

    $source = new RelatedModelSource(priority: 20, relation: 'emails')
        ->event('sent_at', 'email_sent')
        ->event('received_at', 'email_received');

    $entries = collect($source->resolve($person, new Window(cap: 10)));

    expect($entries->pluck('event')->sort()->values()->all())
        ->toBe(['email_received', 'email_sent']);
});

it('skips rows where the date column is null', function (): void {
    $person = Person::factory()->create();
    Email::factory()->for($person)->create(['sent_at' => null]);

    $source = new RelatedModelSource(priority: 20, relation: 'emails')
        ->event('sent_at', 'email_sent');

    $entries = collect($source->resolve($person, new Window(cap: 10)));

    expect($entries)->toBeEmpty();
});

it('honors when: closure filters', function (): void {
    $person = Person::factory()->create();
    $kept = Email::factory()->for($person)->create([
        'sent_at' => CarbonImmutable::now(),
        'subject' => 'keep',
    ]);
    Email::factory()->for($person)->create([
        'sent_at' => CarbonImmutable::now(),
        'subject' => 'drop',
    ]);

    $source = new RelatedModelSource(priority: 20, relation: 'emails')
        ->event('sent_at', 'email_sent', when: fn (Email $e): bool => $e->subject === 'keep');

    $entries = collect($source->resolve($person, new Window(cap: 10)));

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->relatedModel->id)->toBe($kept->id);
});

it('produces dedup key scoped by related class and id', function (): void {
    $person = Person::factory()->create();
    $email = Email::factory()->for($person)->create([
        'sent_at' => CarbonImmutable::parse('2026-04-17T09:00:00Z'),
    ]);

    $source = new RelatedModelSource(priority: 20, relation: 'emails')
        ->event('sent_at', 'email_sent');

    $entry = collect($source->resolve($person, new Window(cap: 10)))->first();

    expect($entry->dedupKey)->toBe(Email::class.':'.$email->id.':2026-04-17T09:00:00');
});
