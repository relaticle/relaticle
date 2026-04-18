<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Email;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;
use Relaticle\ActivityLog\Timeline\Sources\RelatedModelSource;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;

it('caches paginated results for the TTL', function (): void {
    $person = Person::factory()->create();
    Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::now()]);

    $first = TimelineBuilder::make($person)
        ->fromRelation('emails', fn (RelatedModelSource $s): RelatedModelSource => $s->event('sent_at', 'email_sent'))
        ->cached(ttlSeconds: 60)
        ->paginate(perPage: 5);

    $firstCount = $first->total();

    Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::now()]);

    $second = TimelineBuilder::make($person)
        ->fromRelation('emails', fn (RelatedModelSource $s): RelatedModelSource => $s->event('sent_at', 'email_sent'))
        ->cached(ttlSeconds: 60)
        ->paginate(perPage: 5);

    expect($second->total())->toBe($firstCount);
});

it('forgetTimelineCache() invalidates the cache', function (): void {
    $person = Person::factory()->create();
    Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::now()]);

    TimelineBuilder::make($person)
        ->fromRelation('emails', fn (RelatedModelSource $s): RelatedModelSource => $s->event('sent_at', 'email_sent'))
        ->cached(ttlSeconds: 60)
        ->paginate(perPage: 5);

    Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::now()]);

    $person->forgetTimelineCache();

    $after = TimelineBuilder::make($person)
        ->fromRelation('emails', fn (RelatedModelSource $s): RelatedModelSource => $s->event('sent_at', 'email_sent'))
        ->cached(ttlSeconds: 60)
        ->paginate(perPage: 5);

    expect($after->total())->toBe(2);
});
