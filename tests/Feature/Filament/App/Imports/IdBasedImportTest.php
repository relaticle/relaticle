<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;
use Relaticle\ImportWizard\Filament\Imports\NoteImporter;
use Relaticle\ImportWizard\Filament\Imports\OpportunityImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

describe('ID-Based Record Resolution', function (): void {
    it('resolves existing :dataset when valid ID provided', function (
        string $importerClass,
        string $modelClass,
        string $nameField,
        array $factoryData
    ): void {
        $record = $modelClass::factory()->create(['team_id' => $this->team->id, ...$factoryData]);

        $import = createImportRecord($this->user, $this->team, $importerClass);
        $importer = new $importerClass($import, ['id' => 'id', $nameField => $nameField], []);

        setImporterData($importer, ['id' => $record->id, $nameField => 'Updated']);
        $resolved = $importer->resolveRecord();

        expect($resolved->id)->toBe($record->id)
            ->and($resolved->exists)->toBeTrue();
    })->with([
        'company' => [CompanyImporter::class, Company::class, 'name', ['name' => 'Original']],
        'opportunity' => [OpportunityImporter::class, Opportunity::class, 'name', ['name' => 'Original']],
        'task' => [TaskImporter::class, Task::class, 'title', ['title' => 'Original']],
        'note' => [NoteImporter::class, Note::class, 'title', ['title' => 'Original']],
    ]);

    it('resolves existing person when valid ID provided', function (): void {
        $company = Company::factory()->create(['team_id' => $this->team->id]);
        $person = People::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'John Doe',
            'company_id' => $company->id,
        ]);

        $import = createImportRecord($this->user, $this->team, PeopleImporter::class);
        $importer = new PeopleImporter($import, ['id' => 'id', 'name' => 'name'], []);

        setImporterData($importer, ['id' => $person->id, 'name' => 'Jane Doe']);
        $record = $importer->resolveRecord();

        expect($record->id)->toBe($person->id)
            ->and($record->exists)->toBeTrue();
    });
});

describe('ID-Based Import Behaviors', function (): void {
    it('fails when ID not found', function (): void {
        $import = createImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter($import, ['id' => 'id', 'name' => 'name'], []);

        setImporterData($importer, ['id' => (string) Str::ulid(), 'name' => 'Test']);

        expect(fn () => $importer->resolveRecord())->toThrow(Exception::class);
    });

    it('validates ULID format', function (): void {
        $import = createImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter($import, ['id' => 'id', 'name' => 'name'], []);

        setImporterData($importer, ['id' => 'invalid-ulid', 'name' => 'Test']);

        expect(fn () => $importer->resolveRecord())->toThrow(Exception::class);
    });

    it('creates new record when ID is blank', function (): void {
        $import = createImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter($import, ['id' => 'id', 'name' => 'name'], []);

        setImporterData($importer, ['id' => '', 'name' => 'New Company']);

        expect($importer->resolveRecord()->exists)->toBeFalse();
    });

    it('ID takes precedence over CREATE_NEW strategy', function (): void {
        $company = Company::factory()->create(['team_id' => $this->team->id, 'name' => 'Original']);

        $import = createImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter($import, ['id' => 'id', 'name' => 'name'], [
            'duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW,
        ]);

        setImporterData($importer, ['id' => $company->id, 'name' => 'Updated via ID']);

        expect($importer->resolveRecord()->id)->toBe($company->id)
            ->and(Company::count())->toBe(1);
    });
});

describe('Team Isolation Security', function (): void {
    it('prevents cross-team ID access for :dataset', function (
        string $importerClass,
        string $modelClass,
        string $nameField
    ): void {
        $otherTeam = Team::factory()->create();
        $otherRecord = $modelClass::factory()->create(['team_id' => $otherTeam->id]);

        $import = createImportRecord($this->user, $this->team, $importerClass);
        $importer = new $importerClass($import, ['id' => 'id', $nameField => $nameField], []);

        setImporterData($importer, ['id' => $otherRecord->id, $nameField => 'Hacked']);

        expect(fn () => $importer->resolveRecord())->toThrow(Exception::class);
    })->with([
        'company' => [CompanyImporter::class, Company::class, 'name'],
        'people' => [PeopleImporter::class, People::class, 'name'],
        'opportunity' => [OpportunityImporter::class, Opportunity::class, 'name'],
        'task' => [TaskImporter::class, Task::class, 'title'],
        'note' => [NoteImporter::class, Note::class, 'title'],
    ]);
});
