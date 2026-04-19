<?php

declare(strict_types=1);

use Illuminate\Pagination\LengthAwarePaginator;
use Relaticle\ActivityLog\Contracts\HasTimeline;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;

it('provides paginateTimeline() as a convenience wrapper', function (): void {
    $person = Person::factory()->create();

    $paginator = $person->paginateTimeline(perPage: 5);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class);
});

it('satisfies the HasTimeline contract', function (): void {
    $person = Person::factory()->create();

    expect($person)->toBeInstanceOf(HasTimeline::class);
});
