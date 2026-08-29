<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Event;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Jobs\ExecuteImportJob;
use Relaticle\ImportWizard\Store\ImportStore;
use Relaticle\ImportWizard\Support\EntityLinkResolver;
use Tests\Helpers\ImportExecutionFixture;

mutates(ExecuteImportJob::class, EntityLinkResolver::class);

beforeEach(function (): void {
    Event::fake()->except([TeamCreated::class]);

    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;

    Filament::setTenant($this->team);
});

afterEach(function (): void {
    if (isset($this->import)) {
        ImportStore::load($this->import->id)?->destroy();
        $this->import->delete();
    }
});

it('processes 1000 row create import', function (): void {
    $rows = [];
    for ($i = 2; $i <= 1001; $i++) {
        $rows[] = ImportExecutionFixture::row($i, ['Name' => "Person {$i}"], ['match_action' => RowMatchAction::Create->value]);
    }

    ImportExecutionFixture::readyStore($this, ['Name'], $rows, [
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->status)->toBe(ImportStatus::Completed);

    expect($import->created_rows)->toBe(1000)
        ->and($import->failed_rows)->toBe(0);
})->group('slow');

it('processes 1000 row mixed operations import', function (): void {
    $existingPeople = People::factory()->count(50)->create([
        'team_id' => $this->team->id,
    ]);

    $rows = [];
    $rowNumber = 2;

    foreach ($existingPeople as $person) {
        $rows[] = ImportExecutionFixture::row($rowNumber++, ['ID' => (string) $person->id, 'Name' => "Updated {$person->name}"], [
            'match_action' => RowMatchAction::Update->value,
            'matched_id' => (string) $person->id,
        ]);
    }

    for ($i = 0; $i < 25; $i++) {
        $rows[] = ImportExecutionFixture::row($rowNumber++, ['ID' => (string) (900000 + $i), 'Name' => "Ghost {$i}"], [
            'match_action' => RowMatchAction::Skip->value,
        ]);
    }

    for ($i = 0; $i < 426; $i++) {
        $rows[] = ImportExecutionFixture::row($rowNumber++, ['ID' => '', 'Name' => "New Person {$i}"], [
            'match_action' => RowMatchAction::Create->value,
        ]);
    }

    ImportExecutionFixture::readyStore($this, ['ID', 'Name'], $rows, [
        ColumnData::toField(source: 'ID', target: 'id'),
        ColumnData::toField(source: 'Name', target: 'name'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(426)
        ->and($import->updated_rows)->toBe(50)
        ->and($import->skipped_rows)->toBe(25)
        ->and($import->failed_rows)->toBe(0)
        ->and($import->status)->toBe(ImportStatus::Completed);
})->group('slow');

it('processes 1000 rows with entity link relationships and deduplication', function (): void {
    $companyNames = [];
    for ($i = 0; $i < 20; $i++) {
        $companyNames[] = "Company {$i}";
    }

    $rows = [];
    for ($i = 2; $i <= 502; $i++) {
        $companyName = $companyNames[($i - 2) % count($companyNames)];
        $relationships = json_encode([
            ['relationship' => 'company', 'action' => 'create', 'id' => null, 'name' => $companyName, 'behavior' => MatchBehavior::Create->value],
        ]);

        $rows[] = ImportExecutionFixture::row($i, ['Name' => "Person {$i}", 'Company' => $companyName], [
            'match_action' => RowMatchAction::Create->value,
            'relationships' => $relationships,
        ]);
    }

    ImportExecutionFixture::readyStore($this, ['Name', 'Company'], $rows, [
        ColumnData::toField(source: 'Name', target: 'name'),
        ColumnData::toEntityLink(source: 'Company', matcherKey: 'name', entityLinkKey: 'company'),
    ]);

    ImportExecutionFixture::run($this);

    $import = $this->import->fresh();
    expect($import->created_rows)->toBe(501)
        ->and($import->failed_rows)->toBe(0)
        ->and($import->status)->toBe(ImportStatus::Completed);

    $companies = Company::where('team_id', $this->team->id)
        ->whereIn('name', $companyNames)
        ->get();

    expect($companies)->toHaveCount(20);
})->group('slow');
