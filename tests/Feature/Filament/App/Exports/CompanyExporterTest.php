<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\App\Exports;

use App\Enums\CustomFields\CompanyField;
use App\Filament\Exports\CompanyExporter;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Export;
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

mutates(CompanyExporter::class);

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

test('exports company records', function () {
    Livewire::test(ListCompanies::class)
        ->assertActionExists('export')
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();

    expect($export)->not->toBeNull()
        ->and($export->exporter)->toBe(CompanyExporter::class)
        ->and($export->file_disk)->toBe('local')
        ->and($export->team_id)->toBe($this->team->id);
});

test('exports respect team scoping', function () {
    $otherTeam = Team::factory()->create(['personal_team' => false]);
    $this->user->teams()->attach($otherTeam);

    Livewire::test(ListCompanies::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();

    expect($export->team_id)->toBe($this->team->id);
});

test('export columns include system-seeded custom fields', function () {
    TenantContextService::setTenantId($this->team->id);

    $columns = CompanyExporter::getColumns();
    $columnLabels = collect($columns)->map(fn ($column) => $column->getLabel())->all();

    foreach (CompanyField::cases() as $field) {
        expect($columnLabels)->toContain($field->getDisplayName());
    }
});

test('export columns include user-created custom fields', function () {
    TenantContextService::setTenantId($this->team->id);

    CustomField::forceCreate([
        'name' => 'Company Size',
        'code' => 'company_size',
        'type' => 'text',
        'entity_type' => 'company',
        'tenant_id' => $this->team->id,
        'sort_order' => 99,
        'active' => true,
        'system_defined' => false,
        'settings' => new CustomFieldSettingsData,
    ]);

    $columns = CompanyExporter::getColumns();
    $columnLabels = collect($columns)->map(fn ($column) => $column->getLabel())->all();

    expect($columnLabels)->toContain('Company Size');
});

test('export generates CSV with correct data', function () {
    Storage::fake('local');

    Company::factory()->create([
        'team_id' => $this->team->id,
        'name' => 'Acme Corp',
    ]);

    Livewire::test(ListCompanies::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();
    $directory = $export->getFileDirectory();

    $headers = Storage::disk('local')->get("{$directory}/headers.csv");
    $data = Storage::disk('local')->get("{$directory}/0000000000000001.csv");

    expect($headers)->toContain('Company Name')
        ->and($headers)->toContain('ICP')
        ->and($data)->toContain('Acme Corp');
});

test('export headers name the timezone the values are written in', function () {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();

    $labels = collect(CompanyExporter::getColumns())->map(fn ($column) => $column->getLabel())->all();

    expect($labels)->toContain('Created At (Asia/Tokyo)')
        ->and($labels)->toContain('Updated At (Asia/Tokyo)');
});

test('export headers fall back to the app timezone for a user without one', function () {
    $this->user->forceFill(['timezone' => null])->save();

    $labels = collect(CompanyExporter::getColumns())->map(fn ($column) => $column->getLabel())->all();

    expect($labels)->toContain('Created At (UTC)');
});

test('export values are converted out of utc into the requesting user timezone', function () {
    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();

    $company = Company::factory()->create([
        'team_id' => $this->team->id,
        'created_at' => Date::parse('2026-08-18 23:30:00', 'UTC'),
    ]);

    Livewire::test(ListCompanies::class)
        ->callAction('export')
        ->assertHasNoFormErrors();

    $export = Export::latest()->first();

    expect($export->user_id)->toBe($this->user->getKey());

    $exporter = new CompanyExporter($export, ['created_at' => 'Created At'], []);
    $row = $exporter($company->fresh());

    // 23:30 UTC on the 18th is 08:30 the next morning in Tokyo — the date rolls over.
    expect($row[0])->toBe('2026-08-19 08:30:00');
});
