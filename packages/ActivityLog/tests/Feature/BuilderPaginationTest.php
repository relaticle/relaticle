<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Email;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;

it('paginates results into a LengthAwarePaginator', function (): void {
    $person = Person::factory()->create();

    for ($i = 1; $i <= 15; $i++) {
        Email::factory()->for($person)->create([
            'sent_at' => CarbonImmutable::parse('2026-04-17')->subMinutes($i),
        ]);
    }

    $paginator = TimelineBuilder::make($person)
        ->fromRelation('emails', fn ($s) => $s->event('sent_at', 'email_sent'))
        ->paginate(perPage: 5, page: 1);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->perPage())->toBe(5)
        ->and($paginator->currentPage())->toBe(1)
        ->and($paginator->items())->toHaveCount(5);
});

it('returns correct entries for page 2', function (): void {
    $person = Person::factory()->create();

    for ($i = 1; $i <= 15; $i++) {
        Email::factory()->for($person)->create([
            'sent_at' => CarbonImmutable::parse('2026-04-17')->subMinutes($i),
        ]);
    }

    $page1 = TimelineBuilder::make($person)
        ->fromRelation('emails', fn ($s) => $s->event('sent_at', 'email_sent'))
        ->paginate(perPage: 5, page: 1);

    $page2 = TimelineBuilder::make($person)
        ->fromRelation('emails', fn ($s) => $s->event('sent_at', 'email_sent'))
        ->paginate(perPage: 5, page: 2);

    expect($page1->items())->not->toMatchArray($page2->items());
});

it('uses config default for perPage when null', function (): void {
    config()->set('activity-log.default_per_page', 7);

    $person = Person::factory()->create();

    for ($i = 1; $i <= 15; $i++) {
        Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::now()->subMinutes($i)]);
    }

    $paginator = TimelineBuilder::make($person)
        ->fromRelation('emails', fn ($s) => $s->event('sent_at', 'email_sent'))
        ->paginate();

    expect($paginator->perPage())->toBe(7);
});
