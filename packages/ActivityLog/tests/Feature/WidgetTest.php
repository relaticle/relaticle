<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Relaticle\ActivityLog\Filament\Widgets\ActivityLogWidget;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Email;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;

it('aggregates entries across subjects bounded by max_subjects', function (): void {
    for ($i = 0; $i < 3; $i++) {
        $person = Person::factory()->create();
        Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::now()->subMinutes($i)]);
    }

    $widget = new class extends ActivityLogWidget
    {
        protected function model(): string
        {
            return Person::class;
        }

        protected function perPage(): int
        {
            return 5;
        }
    };

    $entries = $widget->getEntries();

    expect(count($entries))->toBeGreaterThan(0)
        ->and(count($entries))->toBeLessThanOrEqual(5);
});

it('caps at perPage', function (): void {
    for ($i = 0; $i < 10; $i++) {
        $person = Person::factory()->create();
        Email::factory()->for($person)->create(['sent_at' => CarbonImmutable::now()->subMinutes($i)]);
    }

    $widget = new class extends ActivityLogWidget
    {
        protected function model(): string
        {
            return Person::class;
        }

        protected function perPage(): int
        {
            return 3;
        }
    };

    expect(count($widget->getEntries()))->toBe(3);
});
