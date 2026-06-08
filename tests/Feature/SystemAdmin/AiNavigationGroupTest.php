<?php

declare(strict_types=1);

use Filament\Facades\Filament;

it('registers the AI navigation group in the sysadmin panel', function (): void {
    $panel = Filament::getPanel('sysadmin');
    $labels = collect($panel->getNavigationGroups())
        ->map(fn ($group) => $group->getLabel())
        ->all();

    expect($labels)->toContain('AI');
});

it('orders the AI group between User Management and CRM', function (): void {
    $panel = Filament::getPanel('sysadmin');
    $labels = collect($panel->getNavigationGroups())
        ->map(fn ($group) => $group->getLabel())
        ->values()
        ->all();

    $userManagementIndex = array_search('User Management', $labels, true);
    $aiIndex = array_search('AI', $labels, true);
    $crmIndex = array_search('CRM', $labels, true);

    expect($aiIndex)->toBeGreaterThan($userManagementIndex)
        ->and($aiIndex)->toBeLessThan($crmIndex);
});
