<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Exports;

use App\Enums\CustomFields\TaskField;
use App\Filament\Exports\TaskExporter;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\CustomField;
use App\Models\Export;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Jetstream\Events\TeamCreated;
use Livewire\Livewire;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;

beforeEach(function () {
    Event::fake()->except([
        TeamCreated::class,
        'eloquent.creating: App\\Models\\Team',
    ]);

    $this->team = Team::factory()->create();
    $this->user = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

test('exports task records', function () {
    Livewire::test(ManageTasks::class)
        ->assertActionExists('export')
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();

    expect($export)->not->toBeNull()
        ->and($export->exporter)->toBe(TaskExporter::class)
        ->and($export->file_disk)->toBe('local')
        ->and($export->team_id)->toBe($this->team->id);
});

test('exports respect team scoping', function () {
    $otherTeam = Team::factory()->create(['personal_team' => false]);
    $this->user->teams()->attach($otherTeam);

    Livewire::test(ManageTasks::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();

    expect($export->team_id)->toBe($this->team->id);
});

test('export columns include system-seeded custom fields', function () {
    TenantContextService::setTenantId($this->team->id);

    $columns = TaskExporter::getColumns();
    $columnLabels = collect($columns)->map(fn ($column) => $column->getLabel())->all();

    foreach (TaskField::cases() as $field) {
        expect($columnLabels)->toContain($field->getDisplayName());
    }
});

test('export columns include user-created custom fields', function () {
    TenantContextService::setTenantId($this->team->id);

    CustomField::forceCreate([
        'name' => 'Estimated Hours',
        'code' => 'estimated_hours',
        'type' => 'number',
        'entity_type' => 'task',
        'tenant_id' => $this->team->id,
        'sort_order' => 99,
        'active' => true,
        'system_defined' => false,
        'settings' => new CustomFieldSettingsData,
    ]);

    $columns = TaskExporter::getColumns();
    $columnLabels = collect($columns)->map(fn ($column) => $column->getLabel())->all();

    expect($columnLabels)->toContain('Estimated Hours');
});

test('export generates CSV with correct data', function () {
    Storage::fake('local');

    Task::factory()->create([
        'team_id' => $this->team->id,
        'title' => 'Fix login bug',
    ]);

    Livewire::test(ManageTasks::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();
    $directory = $export->getFileDirectory();

    $headers = Storage::disk('local')->get("{$directory}/headers.csv");
    $data = Storage::disk('local')->get("{$directory}/0000000000000001.csv");

    expect($headers)->toContain('Title')
        ->and($headers)->toContain('Status')
        ->and($data)->toContain('Fix login bug');
});
