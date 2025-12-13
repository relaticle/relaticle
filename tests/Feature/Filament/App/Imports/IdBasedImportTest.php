<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;
use Relaticle\ImportWizard\Filament\Imports\NoteImporter;
use Relaticle\ImportWizard\Filament\Imports\OpportunityImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;

use function Pest\Laravel\actingAs;

// Helper function to create CSV files
function createTestCsv(array $data): string
{
    $tmpFile = tmpfile();
    $path = stream_get_meta_data($tmpFile)['uri'];

    $file = fopen($path, 'w');
    foreach ($data as $row) {
        fputcsv($file, $row);
    }
    fclose($file);

    return $path;
}

// Helper function to run imports
function runTestImport(string $importerClass, string $csvPath, int $teamId, int $userId, array $options = []): Import
{
    $import = Import::factory()->create([
        'team_id' => $teamId,
        'user_id' => $userId,
        'file_path' => $csvPath,
        'importer' => $importerClass,
    ]);

    // Get column names from first row
    $file = fopen($csvPath, 'r');
    $headers = fgetcsv($file);
    fclose($file);

    $columnMap = [];
    foreach ($headers as $header) {
        $columnMap[$header] = $header;
    }

    $importer = new $importerClass($import, $columnMap, $options);
    $importer->import();

    return $import->refresh();
}

beforeEach(function (): void {
    $this->team = Team::factory()->create();
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->teams()->attach($this->team);

    actingAs($this->user);
});

describe('ID-Based Company Import', function (): void {
    it('updates company when valid ID provided', function (): void {
        $company = Company::factory()->create(['team_id' => $this->team->id, 'name' => 'Original Name']);

        $csvPath = createTestCsv([
            ['id', 'name'],
            [$company->id, 'Updated Name'],
        ]);

        $import = runTestImport(CompanyImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(1)
            ->and($import->getFailedRowsCount())->toBe(0)
            ->and($company->refresh()->name)->toBe('Updated Name');
    });

    it('fails when ID not found', function (): void {
        $fakeId = (string) Str::uuid();

        $csvPath = createTestCsv([
            ['id', 'name'],
            [$fakeId, 'Test Company'],
        ]);

        $import = runTestImport(CompanyImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(0)
            ->and($import->getFailedRowsCount())->toBe(1);
    });

    it('fails when ID format is invalid', function (): void {
        $csvPath = createTestCsv([
            ['id', 'name'],
            ['not-a-uuid', 'Test Company'],
        ]);

        $import = runTestImport(CompanyImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(0)
            ->and($import->getFailedRowsCount())->toBe(1);
    });

    it('prevents cross-team ID access', function (): void {
        $otherTeam = Team::factory()->create();
        $otherCompany = Company::factory()->create(['team_id' => $otherTeam->id, 'name' => 'Other Company']);

        $csvPath = createTestCsv([
            ['id', 'name'],
            [$otherCompany->id, 'Hacked Name'],
        ]);

        $import = runTestImport(CompanyImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->getFailedRowsCount())->toBe(1)
            ->and($otherCompany->refresh()->name)->toBe('Other Company');
    });

    it('supports mixed ID and non-ID rows', function (): void {
        $existing = Company::factory()->create(['team_id' => $this->team->id, 'name' => 'Existing']);

        $csvPath = createTestCsv([
            ['id', 'name'],
            [$existing->id, 'Updated Existing'],
            ['', 'New Company'],
        ]);

        $import = runTestImport(CompanyImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(2)
            ->and($existing->refresh()->name)->toBe('Updated Existing')
            ->and(Company::where('name', 'New Company')->exists())->toBeTrue();
    });

    it('ID takes precedence over duplicate strategy', function (): void {
        $company = Company::factory()->create(['team_id' => $this->team->id, 'name' => 'Original']);

        $csvPath = createTestCsv([
            ['id', 'name'],
            [$company->id, 'Updated via ID'],
        ]);

        // Even with CREATE_NEW strategy, ID should cause update
        $import = runTestImport(CompanyImporter::class, $csvPath, $this->team->id, $this->user->id, [
            'duplicate_handling' => DuplicateHandlingStrategy::CREATE_NEW,
        ]);

        expect($import->successful_rows)->toBe(1)
            ->and(Company::count())->toBe(1)
            ->and($company->refresh()->name)->toBe('Updated via ID');
    });
});

describe('ID-Based People Import', function (): void {
    it('updates person when valid ID provided', function (): void {
        $company = Company::factory()->create(['team_id' => $this->team->id]);
        $person = People::factory()->create(['team_id' => $this->team->id, 'name' => 'John Doe', 'company_id' => $company->id]);

        $csvPath = createTestCsv([
            ['id', 'name', 'company_name'],
            [$person->id, 'Jane Doe', $company->name],
        ]);

        $import = runTestImport(PeopleImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(1)
            ->and($person->refresh()->name)->toBe('Jane Doe');
    });

    it('ID resolution bypasses email matching', function (): void {
        $company = Company::factory()->create(['team_id' => $this->team->id]);
        $person = People::factory()->create(['team_id' => $this->team->id, 'name' => 'Original', 'company_id' => $company->id]);

        // Different email value, same ID - should still update by ID
        $csvPath = createTestCsv([
            ['id', 'name', 'company_name'],
            [$person->id, 'Updated by ID', $company->name],
        ]);

        $import = runTestImport(PeopleImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(1)
            ->and($person->refresh()->name)->toBe('Updated by ID');
    });
});

describe('ID-Based Opportunity Import', function (): void {
    it('updates opportunity when valid ID provided', function (): void {
        $opportunity = Opportunity::factory()->create(['team_id' => $this->team->id, 'name' => 'Old Deal']);

        $csvPath = createTestCsv([
            ['id', 'name'],
            [$opportunity->id, 'Updated Deal'],
        ]);

        $import = runTestImport(OpportunityImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(1)
            ->and($opportunity->refresh()->name)->toBe('Updated Deal');
    });
});

describe('ID-Based Task Import', function (): void {
    it('updates task when valid ID provided', function (): void {
        $task = Task::factory()->create(['team_id' => $this->team->id, 'title' => 'Old Task']);

        $csvPath = createTestCsv([
            ['id', 'title'],
            [$task->id, 'Updated Task'],
        ]);

        $import = runTestImport(TaskImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(1)
            ->and($task->refresh()->title)->toBe('Updated Task');
    });
});

describe('ID-Based Note Import', function (): void {
    it('updates note when valid ID provided', function (): void {
        $note = Note::factory()->create(['team_id' => $this->team->id, 'title' => 'Old Note']);

        $csvPath = createTestCsv([
            ['id', 'title'],
            [$note->id, 'Updated Note'],
        ]);

        $import = runTestImport(NoteImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(1)
            ->and($note->refresh()->title)->toBe('Updated Note');
    });
});

describe('ID Column Validation', function (): void {
    it('accepts blank ID values for new records', function (): void {
        $csvPath = createTestCsv([
            ['id', 'name'],
            ['', 'New Company'],
        ]);

        $import = runTestImport(CompanyImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(1)
            ->and(Company::where('name', 'New Company')->exists())->toBeTrue();
    });

    it('validates UUID format', function (): void {
        $csvPath = createTestCsv([
            ['id', 'name'],
            ['invalid-uuid', 'Test'],
            ['12345', 'Test2'],
            ['not-a-real-id', 'Test3'],
        ]);

        $import = runTestImport(CompanyImporter::class, $csvPath, $this->team->id, $this->user->id);

        expect($import->successful_rows)->toBe(0)
            ->and($import->getFailedRowsCount())->toBe(3);
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
            $csvPath = createTestCsv([
                ['id', $case['column']],
                [$case['id'], $case['value']],
            ]);

            $import = runTestImport($case['importer'], $csvPath, test()->team->id, test()->user->id);

            expect($import->getFailedRowsCount())->toBe(1);
        }
    });
});
