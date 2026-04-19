<?php

declare(strict_types=1);

use Relaticle\ActivityLog\Filament\RelationManagers\ActivityLogRelationManager;

it('exposes fluent configuration', function (): void {
    $rm = new ActivityLogRelationManager;
    $rm->groupByDate(false)->perPage(30);

    expect($rm->isGrouped())->toBeFalse()
        ->and($rm->getPerPageCount())->toBe(30);
});
