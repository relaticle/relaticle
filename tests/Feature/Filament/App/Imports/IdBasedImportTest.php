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
use Relaticle\ImportWizard\Models\Import;

function createTestImportRecord(User $user, Team $team, string $importer): Import
{
    return Import::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'importer' => $importer,
        'file_name' => 'test.csv',
        'file_path' => '/tmp/test.csv',
        'total_rows' => 1,
    ]);
}

function setImporterData(object $importer, array $data): void
{
    $reflection = new ReflectionClass($importer);
    $dataProperty = $reflection->getProperty('data');
    $dataProperty->setValue($importer, $data);
}

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->teams()->attach($this->team);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

describe('ID-Based Company Import', function (): void {
    it('resolves existing company when valid ID provided', function (): void {
        $company = Company::factory()->create(['team_id' => $this->team->id, 'name' => 'Original Name']);

        $import = createTestImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter($import, ['id' => 'id', 'name' => 'name'], []);

        setImporterData($importer, ['id' => $company->id, 'name' => 'Updated Name']);
        $record = $importer->resolveRecord();

        expect($record->id)->toBe($company->id)
            ->and($record->exists)->toBeTrue();
    });

    it('fails when ID not found', function (): void {
        $fakeId = (string) Str::ulid();

        $import = createTestImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter($import, ['id' => 'id', 'name' => 'name'], []);

        setImporterData($importer, ['id' => $fakeId, 'name' => 'Test Company']);

        expect(fn () => $importer->resolveRecord())->toThrow(Exception::class);
    });

    it('prevents cross-team ID access', function (): void {
        $otherTeam = Team::factory()->create();
        $otherCompany = Company::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Other Company']);

        $import = createTestImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter($import, ['id' => 'id', 'name' => 'name'], []);

        setImporterData($importer, ['id' => $otherCompany->id, 'name' => 'Hacked Name']);

        expect(fn () => $importer->resolveRecord())->toThrow(Exception::class);
        expect($otherCompany->refresh()->name)->toBe('Other Company');
    });

    it('creates new record when ID is blank', function (): void {
        $import = createTestImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter($import, ['id' => 'id', 'name' => 'name'], []);

        setImporterData($importer, ['id' => '', 'name' => 'New Company']);
        $record = $importer->resolveRecord();

        expect($record->exists)->toBeFalse();
    });

    it('ID takes precedence over duplicate strategy', function (): void {
        $company = Company::factory()->create(['team_id' => $this->team->id, 'name' => 'Original']);

        $import = createTestImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter($import, ['id' => 'id', 'name' => 'name'], [
            'duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW,
        ]);

        setImporterData($importer, ['id' => $company->id, 'name' => 'Updated via ID']);
        $record = $importer->resolveRecord();

        expect($record->id)->toBe($company->id)
            ->and(Company::count())->toBe(1);
    });
});

describe('ID-Based People Import', function (): void {
    it('resolves existing person when valid ID provided', function (): void {
        $company = Company::factory()->create(['team_id' => $this->team->id]);
        $person = People::factory()->create(['team_id' => $this->team->id, 'name' => 'John Doe', 'company_id' => $company->id]);

        $import = createTestImportRecord($this->user, $this->team, PeopleImporter::class);
        $importer = new PeopleImporter($import, ['id' => 'id', 'name' => 'name'], []);

        setImporterData($importer, ['id' => $person->id, 'name' => 'Jane Doe']);
        $record = $importer->resolveRecord();

        expect($record->id)->toBe($person->id)
            ->and($record->exists)->toBeTrue();
    });
});

describe('ID-Based Opportunity Import', function (): void {
    it('resolves existing opportunity when valid ID provided', function (): void {
        $opportunity = Opportunity::factory()->create(['team_id' => $this->team->id, 'name' => 'Old Deal']);

        $import = createTestImportRecord($this->user, $this->team, OpportunityImporter::class);
        $importer = new OpportunityImporter($import, ['id' => 'id', 'name' => 'name'], []);

        setImporterData($importer, ['id' => $opportunity->id, 'name' => 'Updated Deal']);
        $record = $importer->resolveRecord();

        expect($record->id)->toBe($opportunity->id)
            ->and($record->exists)->toBeTrue();
    });
});

describe('ID-Based Task Import', function (): void {
    it('resolves existing task when valid ID provided', function (): void {
        $task = Task::factory()->create(['team_id' => $this->team->id, 'title' => 'Old Task']);

        $import = createTestImportRecord($this->user, $this->team, TaskImporter::class);
        $importer = new TaskImporter($import, ['id' => 'id', 'title' => 'title'], []);

        setImporterData($importer, ['id' => $task->id, 'title' => 'Updated Task']);
        $record = $importer->resolveRecord();

        expect($record->id)->toBe($task->id)
            ->and($record->exists)->toBeTrue();
    });
});

describe('ID-Based Note Import', function (): void {
    it('resolves existing note when valid ID provided', function (): void {
        $note = Note::factory()->create(['team_id' => $this->team->id, 'title' => 'Old Note']);

        $import = createTestImportRecord($this->user, $this->team, NoteImporter::class);
        $importer = new NoteImporter($import, ['id' => 'id', 'title' => 'title'], []);

        setImporterData($importer, ['id' => $note->id, 'title' => 'Updated Note']);
        $record = $importer->resolveRecord();

        expect($record->id)->toBe($note->id)
            ->and($record->exists)->toBeTrue();
    });
});

describe('ID Column Validation', function (): void {
    it('validates ULID format', function (): void {
        $import = createTestImportRecord($this->user, $this->team, CompanyImporter::class);
        $importer = new CompanyImporter($import, ['id' => 'id', 'name' => 'name'], []);

        // Invalid ULID should throw exception
        setImporterData($importer, ['id' => 'invalid-ulid', 'name' => 'Test']);

        expect(fn () => $importer->resolveRecord())->toThrow(Exception::class);
    });
});

describe('Security & Team Isolation', function (): void {
    it('enforces team isolation across all entity types', function (): void {
        $otherTeam = Team::factory()->create();

        $otherCompany = Company::factory()->create(['team_id' => $otherTeam->id]);
        $otherPerson = People::factory()->create(['team_id' => $otherTeam->id]);
        $otherOpportunity = Opportunity::factory()->create(['team_id' => $otherTeam->id]);
        $otherTask = Task::factory()->create(['team_id' => $otherTeam->id]);
        $otherNote = Note::factory()->create(['team_id' => $otherTeam->id]);

        $testCases = [
            ['importer' => CompanyImporter::class, 'id' => $otherCompany->id, 'column' => 'name', 'value' => 'Hacked'],
            ['importer' => PeopleImporter::class, 'id' => $otherPerson->id, 'column' => 'name', 'value' => 'Hacked'],
            ['importer' => OpportunityImporter::class, 'id' => $otherOpportunity->id, 'column' => 'name', 'value' => 'Hacked'],
            ['importer' => TaskImporter::class, 'id' => $otherTask->id, 'column' => 'title', 'value' => 'Hacked'],
            ['importer' => NoteImporter::class, 'id' => $otherNote->id, 'column' => 'title', 'value' => 'Hacked'],
        ];

        foreach ($testCases as $case) {
            $import = createTestImportRecord(test()->user, test()->team, $case['importer']);
            $importer = new $case['importer']($import, ['id' => 'id', $case['column'] => $case['column']], []);

            setImporterData($importer, ['id' => $case['id'], $case['column'] => $case['value']]);

            expect(fn () => $importer->resolveRecord())->toThrow(Exception::class);
        }
    });
});
