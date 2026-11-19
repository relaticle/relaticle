<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Models\User;

mutates(CompanyResource::class);

it('renders the companies list in the language the user chose', function (string $locale, string $heading, string $importExport): void {
    $user = User::factory()->withWorkspace()->create(['locale' => $locale]);
    $workspace = $user->ownedWorkspaces()->first();

    visitAs($user, "/app/{$workspace->slug}/companies")
        ->assertSee($heading)
        ->assertSee($importExport);
})->with([
    'en' => ['en', 'Companies', 'Import / Export'],
    'de' => ['de', 'Unternehmen', 'Import / Export'],
    'es' => ['es', 'Empresas', 'Importar / Exportar'],
    'fr' => ['fr', 'Entreprises', 'Importer / Exporter'],
]);
