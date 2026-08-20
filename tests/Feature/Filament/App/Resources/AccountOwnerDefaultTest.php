<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;

mutates(CompanyResource::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('defaults the account owner to the acting user on the create form', function (): void {
    livewire(ListCompanies::class)
        ->mountAction('create')
        ->assertActionDataSet(['account_owner_id' => $this->user->getKey()]);
});

it('leaves the account owner unset when a company is created outside the form', function (): void {
    $company = Company::factory()->create([
        'team_id' => $this->team->getKey(),
        'creator_id' => $this->user->getKey(),
        'account_owner_id' => null,
    ]);

    expect($company->refresh()->account_owner_id)->toBeNull();
});
