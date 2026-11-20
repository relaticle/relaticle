<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\ActivityLog\Filament\Livewire\ActivityLogLivewire;
use Relaticle\ActivityLog\Filament\RelationManagers\ActivityLogRelationManager;

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->company = Company::factory()->for($this->workspace)->create(['name' => 'Trashed Timeline Co']);
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
