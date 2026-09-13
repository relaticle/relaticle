<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Exports;

use App\Enums\CustomFields\OpportunityField;
use App\Events\WorkspaceCreated;
use App\Filament\Exports\OpportunityExporter;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Models\CustomField;
use App\Models\Export;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(OpportunityExporter::class);

beforeEach(function () {
    Event::fake()->except([
        WorkspaceCreated::class,
        'eloquent.creating: App\\Models\\Workspace',
    ]);

    $this->workspace = Workspace::factory()->create();
    $this->user = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->user->workspaces()->attach($this->workspace);

    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
});

test('exports opportunity records', function () {
    Livewire::test(ListOpportunities::class)
        ->assertActionExists('export')
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();

    expect($export)->not->toBeNull()
        ->and($export->exporter)->toBe(OpportunityExporter::class)
        ->and($export->file_disk)->toBe('local')
        ->and($export->workspace_id)->toBe($this->workspace->id);
});

test('exports respect workspace scoping', function () {
    $otherWorkspace = Workspace::factory()->create(['personal_workspace' => false]);
    $this->user->workspaces()->attach($otherWorkspace);

    Livewire::test(ListOpportunities::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();

    expect($export->workspace_id)->toBe($this->workspace->id);
});

test('export columns include system-seeded custom fields', function () {
    TenantContextService::setTenantId($this->workspace->id);

    $columns = OpportunityExporter::getColumns();
    $columnLabels = collect($columns)->map(fn ($column) => $column->getLabel())->all();

    foreach (OpportunityField::cases() as $field) {
        expect($columnLabels)->toContain($field->getDisplayName());
    }
});

test('export columns include user-created custom fields', function () {
    TenantContextService::setTenantId($this->workspace->id);

    CustomField::forceCreate([
        'name' => 'Win Probability',
        'code' => 'win_probability',
        'type' => 'number',
        'entity_type' => 'opportunity',
        'tenant_id' => $this->workspace->id,
        'sort_order' => 99,
        'active' => true,
        'system_defined' => false,
        'settings' => new CustomFieldSettingsData,
    ]);

    $columns = OpportunityExporter::getColumns();
    $columnLabels = collect($columns)->map(fn ($column) => $column->getLabel())->all();

    expect($columnLabels)->toContain('Win Probability');
});

test('export generates CSV with correct data', function () {
    Storage::fake('local');

    Opportunity::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Big Deal',
    ]);

    Livewire::test(ListOpportunities::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();
    $directory = $export->getFileDirectory();

    $headers = Storage::disk('local')->get("{$directory}/headers.csv");
    $data = Storage::disk('local')->get("{$directory}/0000000000000001.csv");

    expect($headers)->toContain('Opportunity Name')
        ->and($headers)->toContain('Amount')
        ->and($data)->toContain('Big Deal');
});
