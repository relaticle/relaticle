<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;
use Relaticle\ImportWizard\Filament\Imports\NoteImporter;
use Relaticle\ImportWizard\Filament\Imports\OpportunityImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;
use Relaticle\ImportWizard\Models\Import;

beforeEach(function (): void {
    ['user' => $this->user, 'team' => $this->team] = setupImportTestContext();
    $this->otherTeam = Team::factory()->create();
});

describe('ID-Based Record Resolution', function (): void {
    it('resolves existing :dataset when valid ID provided', function (
        string $importerClass,
        string $modelClass,
        string $nameField,
        array $factoryData
    ): void {
        if ($modelClass === People::class) {
            $factoryData['company_id'] = Company::factory()->for($this->team)->create()->id;
        }

        $record = $modelClass::factory()->create(['team_id' => $this->team->id, ...$factoryData]);
        $importer = createImporter($importerClass, $this->user, $this->team, ['id' => 'id', $nameField => $nameField], ['id' => $record->id, $nameField => 'Updated'], []);

        expect($importer->resolveRecord())->id->toBe($record->id)->exists->toBeTrue();
    })->with([
        'company' => [CompanyImporter::class, Company::class, 'name', ['name' => 'Original']],
        'opportunity' => [OpportunityImporter::class, Opportunity::class, 'name', ['name' => 'Original']],
        'task' => [TaskImporter::class, Task::class, 'title', ['title' => 'Original']],
        'note' => [NoteImporter::class, Note::class, 'title', ['title' => 'Original']],
        'person' => [PeopleImporter::class, People::class, 'name', ['name' => 'John Doe']],
    ]);
});

describe('ID-Based Import Behaviors', function (): void {
    it(':dataset ID handling', function (string $scenario, string $id, string $expectation): void {
        if ($scenario === 'precedence') {
            $company = Company::factory()->for($this->team)->create(['name' => 'Original']);
            $id = $company->id;
        }

        $importer = createImporter(
            CompanyImporter::class,
            $this->user,
            $this->team,
            ['id' => 'id', 'name' => 'name'],
            ['id' => $id, 'name' => 'Test'],
            $scenario === 'precedence' ? ['duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW] : []
        );

        match ($expectation) {
            'throws' => expect(fn () => $importer->resolveRecord())->toThrow(Exception::class),
            'creates_new' => expect($importer->resolveRecord()->exists)->toBeFalse(),
            'uses_existing' => expect($importer->resolveRecord())->id->toBe($id)->and(Company::count())->toBe(1),
        };
    })->with([
        'fails when ID not found' => ['not found', (string) Str::ulid(), 'throws'],
        'validates ULID format' => ['invalid', 'invalid-ulid', 'throws'],
        'creates new when ID blank' => ['blank', '', 'creates_new'],
        'ID takes precedence over CREATE_NEW' => ['precedence', '', 'uses_existing'],
    ]);
});

describe('Team Isolation', function (): void {
    it('prevents cross-team :dataset ID access', function (string $importerClass, string $modelClass, string $nameField): void {
        $otherRecord = $modelClass::factory()->create(['team_id' => $this->otherTeam->id]);
        $importer = createImporter($importerClass, $this->user, $this->team, ['id' => 'id', $nameField => $nameField], ['id' => $otherRecord->id, $nameField => 'Hacked'], []);

        expect(fn () => $importer->resolveRecord())->toThrow(Exception::class);
    })->with([
        'company' => [CompanyImporter::class, Company::class, 'name'],
        'people' => [PeopleImporter::class, People::class, 'name'],
        'opportunity' => [OpportunityImporter::class, Opportunity::class, 'name'],
        'task' => [TaskImporter::class, Task::class, 'title'],
        'note' => [NoteImporter::class, Note::class, 'title'],
    ]);

    it('creates :dataset records scoped to current team', function (string $importerClass, string $modelClass, array $columnMap): void {
        $import = createImportRecord($this->user, $this->team, $importerClass);
        $importer = new $importerClass($import, $columnMap, []);

        expect($importer->resolveRecord())->toBeInstanceOf($modelClass)->exists->toBeFalse();
    })->with([
        'companies' => [CompanyImporter::class, Company::class, ['name' => 'New Company']],
        'opportunities' => [OpportunityImporter::class, Opportunity::class, ['name' => 'New Opportunity']],
        'tasks' => [TaskImporter::class, Task::class, ['title' => 'New Task']],
        'notes' => [NoteImporter::class, Note::class, ['title' => 'New Note']],
        'people' => [PeopleImporter::class, People::class, ['name' => 'New Person']],
    ]);

    it('resolves :dataset duplicates only from current team', function (string $importerClass, string $modelClass, string $nameField, string $testValue): void {
        $modelClass::factory()->for($this->otherTeam)->create([$nameField => $testValue]);

        $import = createImportRecord($this->user, $this->team, $importerClass);
        $importer = new $importerClass($import, [$nameField => $testValue], ['duplicate_handling' => DuplicateHandlingStrategy::SKIP]);

        expect($importer->resolveRecord()->exists)->toBeFalse();
    })->with([
        'companies' => [CompanyImporter::class, Company::class, 'name', 'Duplicate Test'],
        'opportunities' => [OpportunityImporter::class, Opportunity::class, 'name', 'Big Deal'],
        'tasks' => [TaskImporter::class, Task::class, 'title', 'Follow Up'],
        'notes' => [NoteImporter::class, Note::class, 'title', 'Meeting Notes'],
    ]);
});

describe('Import Model Team Isolation', function (): void {
    it('scopes imports by team_id and prevents cross-team access', function (): void {
        $otherUser = User::factory()->withPersonalTeam()->create();
        $otherImport = createImportRecord($otherUser, $this->otherTeam);
        $myImport = createImportRecord($this->user, $this->team);

        expect($myImport->team_id)->toBe($this->team->id)
            ->and($otherImport->team_id)->toBe($this->otherTeam->id)
            ->and(Import::where('team_id', $this->team->id)->get())
            ->toHaveCount(1)
            ->first()->id->toBe($myImport->id);
    });
});
