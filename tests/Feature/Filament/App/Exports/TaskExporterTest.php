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
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Laravel\Jetstream\Events\TeamCreated;
use Livewire\Livewire;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(TaskExporter::class);

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

    // Matched by prefix rather than equality: a date-time field's header also carries the
    // zone its values are written in, so "Due Date" ships as "Due Date (UTC)".
    foreach (TaskField::cases() as $field) {
        $matching = array_filter(
            $columnLabels,
            fn (string $label): bool => $label === $field->getDisplayName()
                || str_starts_with($label, $field->getDisplayName().' ('),
        );

        expect($matching)->not->toBeEmpty("no export column for {$field->getDisplayName()}");
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

test('export datetimes name and use the requesting user timezone', function () {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();

    $labels = collect(TaskExporter::getColumns())->map(fn ($column) => $column->getLabel())->all();

    expect($labels)->toContain('Created At (Asia/Tokyo)')
        ->and($labels)->toContain('Updated At (Asia/Tokyo)');

    $task = Task::factory()->create([
        'team_id' => $this->team->id,
        'created_at' => Date::parse('2026-08-18 23:30:00', 'UTC'),
    ]);

    Livewire::test(ManageTasks::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $exporter = new TaskExporter(Export::latest()->first(), ['created_at' => 'Created At'], []);

    // 23:30 UTC on the 18th is 08:30 the next morning in Tokyo — the date rolls over.
    expect($exporter($task->fresh())[0])->toBe('2026-08-19 08:30:00');
});

/**
 * Custom-field datetimes reach the exporter from the package, bypassing the helper the
 * native columns use. Left alone they wrote the stored UTC under a bare header, in the
 * same file whose other headers say `(Asia/Tokyo)` — so a due date could land a full
 * calendar day off with nothing signalling it.
 */
test('custom field datetimes export in the user timezone with the zone named', function () {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    TenantContextService::setTenantId($this->team->id);

    $dueDate = CustomField::query()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'task')
        ->where('code', TaskField::DUE_DATE->value)
        ->sole();

    $task = Task::factory()->create(['team_id' => $this->team->id]);
    $task->saveCustomFieldValue($dueDate, '2026-08-18 23:30:00');

    $labels = collect(TaskExporter::getColumns())->map(fn ($column) => $column->getLabel())->all();

    expect($labels)->toContain('Due Date (Asia/Tokyo)');

    Livewire::test(ManageTasks::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $exporter = new TaskExporter(Export::latest()->first(), ['custom_fields.due_date' => 'Due Date'], []);

    expect($exporter($task->fresh())[0])->toBe('2026-08-19 08:30:00');
});

/**
 * The other half: a date has no time of day, so converting it would move the calendar day
 * for every viewer west of UTC. It must stay put, and must not gain the `00:00:00` a
 * Carbon cast invents.
 */
test('custom field dates export unshifted and without a time', function () {
    $this->user->forceFill(['timezone' => 'America/New_York'])->save();
    TenantContextService::setTenantId($this->team->id);

    $field = CustomField::forceCreate([
        'name' => 'Kickoff Day',
        'code' => 'kickoff_day',
        'type' => 'date',
        'entity_type' => 'task',
        'tenant_id' => $this->team->id,
        'sort_order' => 98,
        'active' => true,
        'system_defined' => false,
        'settings' => new CustomFieldSettingsData,
    ]);

    $task = Task::factory()->create(['team_id' => $this->team->id]);
    $task->saveCustomFieldValue($field, '2026-08-19');

    $labels = collect(TaskExporter::getColumns())->map(fn ($column) => $column->getLabel())->all();

    expect($labels)->toContain('Kickoff Day');

    Livewire::test(ManageTasks::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $exporter = new TaskExporter(Export::latest()->first(), ['custom_fields.kickoff_day' => 'Kickoff Day'], []);

    expect($exporter($task->fresh())[0])->toBe('2026-08-19');
});
