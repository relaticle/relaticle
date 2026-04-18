<?php

declare(strict_types=1);

use Relaticle\ActivityLog\Filament\Infolists\Components\ActivityLog;
use Relaticle\ActivityLog\Tests\Fixtures\Models\Person;

it('constructs with fluent API', function (): void {
    $component = ActivityLog::make('timeline')
        ->heading('Activity')
        ->groupByDate()
        ->collapsible()
        ->perPage(15)
        ->emptyState('Nothing here yet.');

    expect($component->isGrouped())->toBeTrue()
        ->and($component->isCollapsible())->toBeTrue()
        ->and($component->getPerPage())->toBe(15)
        ->and($component->getEmptyStateMessage())->toBe('Nothing here yet.');
});

it('resolves subject from record', function (): void {
    $person = Person::factory()->create();
    $component = ActivityLog::make('timeline');
    $component->record($person);

    expect($component->resolveSubject()->is($person))->toBeTrue();
});
