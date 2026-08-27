<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\PeopleResource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Sanctum\Sanctum;

mutates(CompanyResource::class, PeopleResource::class);

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

/**
 * The leak guard. The owner default is deliberately scoped to the Filament create form;
 * if it ever migrates into CreateCompany, an action, an observer or the model, every
 * non-interactive path starts stamping ownership and nothing looks broken.
 *
 * This MUST go through the real API so it exercises CreateCompany::execute(). An earlier
 * version of this test built the record with Company::factory() and passed even when the
 * leak was injected into the action, because the factory never reaches that layer.
 */
it('leaves the account owner unset when a company is created through the API', function (): void {
    $apiUser = User::factory()->withPersonalTeam()->create();
    Sanctum::actingAs($apiUser);

    $this->postJson('/api/v1/companies', ['name' => 'Owner Leak Guard Co'])
        ->assertCreated();

    $company = Company::query()
        ->withoutGlobalScopes()
        ->where('name', 'Owner Leak Guard Co')
        ->firstOrFail();

    expect($company->account_owner_id)->toBeNull();
});
