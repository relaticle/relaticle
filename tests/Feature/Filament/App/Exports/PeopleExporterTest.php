<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Exports;

use App\Enums\CustomFields\PeopleField;
use App\Events\WorkspaceCreated;
use App\Filament\Exports\PeopleExporter;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Models\CustomField;
use App\Models\Export;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(PeopleExporter::class);

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

test('exports people records', function () {
    Livewire::test(ListPeople::class)
        ->assertActionExists('export')
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();

    expect($export)->not->toBeNull()
        ->and($export->exporter)->toBe(PeopleExporter::class)
        ->and($export->file_disk)->toBe('local')
        ->and($export->workspace_id)->toBe($this->workspace->id);
});

test('exports respect workspace scoping', function () {
    $otherWorkspace = Workspace::factory()->create(['personal_workspace' => false]);
    $this->user->workspaces()->attach($otherWorkspace);

    Livewire::test(ListPeople::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();

    expect($export->workspace_id)->toBe($this->workspace->id);
});

test('export columns include system-seeded custom fields', function () {
    TenantContextService::setTenantId($this->workspace->id);

    $columns = PeopleExporter::getColumns();
    $columnLabels = collect($columns)->map(fn ($column) => $column->getLabel())->all();

    foreach (PeopleField::cases() as $field) {
        expect($columnLabels)->toContain($field->getDisplayName());
    }
});

test('export columns include user-created custom fields', function () {
    TenantContextService::setTenantId($this->workspace->id);

    CustomField::forceCreate([
        'name' => 'Lead Score',
        'code' => 'lead_score',
        'type' => 'number',
        'entity_type' => 'people',
        'tenant_id' => $this->workspace->id,
        'sort_order' => 99,
        'active' => true,
        'system_defined' => false,
        'settings' => new CustomFieldSettingsData,
    ]);

    $columns = PeopleExporter::getColumns();
    $columnLabels = collect($columns)->map(fn ($column) => $column->getLabel())->all();

    expect($columnLabels)->toContain('Lead Score');
});

test('export generates CSV with correct data', function () {
    Storage::fake('local');

    People::factory()->create([
        'workspace_id' => $this->workspace->id,
        'name' => 'Jane Doe',
    ]);

    Livewire::test(ListPeople::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();
    $directory = $export->getFileDirectory();

    $headers = Storage::disk('local')->get("{$directory}/headers.csv");
    $data = Storage::disk('local')->get("{$directory}/0000000000000001.csv");

    expect($headers)->toContain('Name')
        ->and($headers)->toContain('Emails')
        ->and($data)->toContain('Jane Doe');
});

test('export datetimes name and use the requesting user timezone', function () {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();

    $labels = collect(PeopleExporter::getColumns())->map(fn ($column) => $column->getLabel())->all();

    expect($labels)->toContain('Created At (Asia/Tokyo)')
        ->and($labels)->toContain('Updated At (Asia/Tokyo)')
        ->and($labels)->toContain('Deleted At (Asia/Tokyo)');

    $person = People::factory()->create([
        'workspace_id' => $this->workspace->id,
        'created_at' => Date::parse('2026-08-18 23:30:00', 'UTC'),
    ]);

    Livewire::test(ListPeople::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $exporter = new PeopleExporter(Export::latest()->first(), ['created_at' => 'Created At', 'deleted_at' => 'Deleted At'], []);
    $row = $exporter($person->fresh());

    // 23:30 UTC on the 18th is 08:30 the next morning in Tokyo, so the date rolls over.
    expect($row[0])->toBe('2026-08-19 08:30:00')
        ->and($row[1])->toBeNull();
});
