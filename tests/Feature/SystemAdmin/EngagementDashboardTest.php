<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Pages\EngagementDashboard;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

it('shows the funnel widget on the engagement dashboard', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');

    livewire(EngagementDashboard::class)->assertOk();
});
