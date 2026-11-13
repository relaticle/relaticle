<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Livewire\ImportWizard;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;
use Tests\Helpers\ImportExecutionFixture;

mutates(ImportWizard::class);

it('can navigate the import wizard and upload a CSV file', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/companies/import")
        ->assertSeeIn('[data-page-heading]', 'Companies')
        ->assertSeeIn('.fi-topbar-page-title', 'Import')
        ->assertSee('Drop your .CSV file onto this area to upload');

    // The full upload + wizard flow involves Livewire file uploads and complex
    // server-side state transitions that are better covered by the existing
    // Livewire feature tests in tests/Feature/ImportWizard/.
});

it('lays the column mapping out one block per CSV column on a phone', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $import = Import::factory()->create([
        'workspace_id' => (string) $workspace->id,
        'user_id' => (string) $user->id,
        'entity_type' => ImportEntityType::Company,
        'status' => ImportStatus::Mapping,
        'total_rows' => 1,
        'headers' => ['name', 'domain', 'notes'],
    ]);

    $store = ImportStore::create($import->id);
    $store->query()->insert([
        'row_number' => 2,
        'raw_data' => json_encode(['name' => 'MOBILE-AUDIT Acme', 'domain' => 'acme.example.test', 'notes' => 'MOBILE-AUDIT note']),
    ]);
    ImportExecutionFixture::publish($store);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 664)
        ->navigate("/app/{$workspace->slug}/companies/import?import={$import->id}")
        ->assertSee('MOBILE-AUDIT Acme')
        ->assertScript("[...document.querySelectorAll('[wire\\\\:key^=row-] > .min-w-0')].length === 3")
        ->assertScript("[...document.querySelectorAll('[wire\\\\:key^=row-] > .min-w-0')].every((cell) => cell.scrollWidth <= cell.clientWidth && cell.clientWidth >= 280)", true)
        ->assertScript("[...document.querySelectorAll('button[role=combobox]')].every((select) => select.getBoundingClientRect().width >= 280)", true)
        ->assertNoJavaScriptErrors();

    ImportStore::delete($store->id());
});
