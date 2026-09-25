<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Models\User;

mutates(CompanyResource::class);

it('renders CompanyResource list page with French labels when locale is fr', function (): void {
    config(['app.locale' => 'fr']);
    app()->setLocale('fr');

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies")
        ->assertSee('Entreprises')
        ->assertSee('Importer / Exporter');
});

it('renders CompanyResource list page with English labels when locale is en', function (): void {
    config(['app.locale' => 'en']);
    app()->setLocale('en');

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies")
        ->assertSee('Companies')
        ->assertSee('Import / Export');
});
