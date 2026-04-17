<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Email;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\ActivityLog\Timeline\TimelineEntry;
use Spatie\Activitylog\Models\Activity;

it('dedups RelatedActivityLogSource and RelatedModelSource at same second, RelatedModel wins by priority', function (): void {
    $person = Person::factory()->create();
    $email = Email::factory()->for($person)->create();

    Activity::query()
        ->where('subject_type', $email->getMorphClass())
        ->where('subject_id', $email->id)
        ->update(['created_at' => $email->created_at]);

    $entries = TimelineBuilder::make($person)
        ->fromActivityLogOf(['emails'])
        ->fromRelation('emails', fn ($s) => $s->event('created_at', 'email_created'))
        ->deduplicate()
        ->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->event)->toBe('email_created')
        ->and($entries->first()->type)->toBe('related_model');
});

it('priority override swaps the winner', function (): void {
    $person = Person::factory()->create();
    $email = Email::factory()->for($person)->create();

    Activity::query()
        ->where('subject_type', $email->getMorphClass())
        ->where('subject_id', $email->id)
        ->update(['created_at' => $email->created_at]);

    $entries = TimelineBuilder::make($person)
        ->fromActivityLogOf(['emails'], priority: 100)
        ->fromRelation('emails', fn ($s) => $s->event('created_at', 'email_created'), priority: 20)
        ->deduplicate()
        ->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->type)->toBe('activity_log');
});

it('dedupKeyUsing() overrides the per-entry dedup key', function (): void {
    $person = Person::factory()->create();
    Email::factory()->for($person)->create([
        'sent_at' => CarbonImmutable::parse('2026-04-17T10:00:00Z'),
        'received_at' => CarbonImmutable::parse('2026-04-17T10:00:00Z'),
    ]);

    $entries = TimelineBuilder::make($person)
        ->fromRelation('emails', fn ($s) => $s->event('sent_at', 'email_sent')->event('received_at', 'email_received'))
        ->dedupKeyUsing(fn (TimelineEntry $e): string => $e->relatedModel::class.':'.$e->relatedModel->id)
        ->deduplicate()
        ->get();

    expect($entries)->toHaveCount(1);
});

it('deduplicate(false) skips dedup entirely', function (): void {
    $person = Person::factory()->create();
    $email = Email::factory()->for($person)->create();

    Activity::query()
        ->where('subject_type', $email->getMorphClass())
        ->where('subject_id', $email->id)
        ->update(['created_at' => $email->created_at]);

    $entries = TimelineBuilder::make($person)
        ->fromActivityLogOf(['emails'])
        ->fromRelation('emails', fn ($s) => $s->event('created_at', 'email_created'))
        ->deduplicate(false)
        ->get();

    expect($entries->count())->toBeGreaterThan(1);
});
