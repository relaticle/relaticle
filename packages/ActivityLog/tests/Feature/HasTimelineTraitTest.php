<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Relaticle\ActivityLog\Concerns\HasTimeline;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;

it('provides paginateTimeline() as a convenience wrapper', function (): void {
    $person = Person::factory()->create();

    $paginator = $person->paginateTimeline(perPage: 5);

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class);
});

it('throws if a class uses HasTimeline without implementing timeline()', function (): void {
    $model = new class extends Model
    {
        use HasTimeline;
    };

    expect(fn () => $model->timeline())->toThrow(LogicException::class);
});
