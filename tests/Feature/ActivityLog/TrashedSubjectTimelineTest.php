<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\ActivityLog\Filament\Livewire\ActivityLogLivewire;
use Relaticle\ActivityLog\Filament\RelationManagers\ActivityLogRelationManager;

/**
 * A trashed record is still reachable — the resources drop the soft-delete scope
 * so it can be reviewed and restored — and its timeline has to load with it.
 * Resolving the subject without dropping that scope 404s the whole tab, which is
 * exactly the history an admin opens a deleted record to read.
 */
beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->company = Company::factory()->for($this->team)->create(['name' => 'Trashed Timeline Co']);
    $this->company->update(['name' => 'Renamed Before Deletion']);
    $this->company->delete();
});

it('renders the timeline component for a trashed subject', function (): void {
    livewire(ActivityLogLivewire::class, [
        'subjectClass' => Company::class,
        'subjectKey' => $this->company->getKey(),
    ])->assertOk();
});

it('renders the activity relation manager for a trashed owner record', function (): void {
    livewire(ActivityLogRelationManager::class, [
        'ownerRecord' => $this->company,
        'pageClass' => ViewCompany::class,
    ])->assertOk();
});
