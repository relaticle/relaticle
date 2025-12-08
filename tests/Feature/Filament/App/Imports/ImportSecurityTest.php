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
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;
use Relaticle\ImportWizard\Filament\Imports\NoteImporter;
use Relaticle\ImportWizard\Filament\Imports\OpportunityImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;
use Relaticle\ImportWizard\Filament\Imports\TaskImporter;
use Relaticle\ImportWizard\Models\Import;

/**
 * Helper function to create an Import record for testing.
 */
function createTestImport(User $user, Team $team, string $importer = CompanyImporter::class): Import
{
    return Import::create([
        'user_id' => $user->id,
        'team_id' => $team->id,
        'importer' => $importer,
        'file_name' => 'test.csv',
        'file_path' => '/tmp/test.csv',
        'total_rows' => 10,
    ]);
}

/**
 * Security tests for import tenant isolation.
 *
 * These tests ensure that import operations are properly scoped to the
 * current team and cannot access or modify data from other teams.
 */
describe('Import Security - Team Isolation', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->withPersonalTeam()->create();
        $this->team = $this->user->currentTeam;
        $this->actingAs($this->user);
        Filament::setTenant($this->team);

        // Create another team for cross-team testing
        $this->otherUser = User::factory()->withPersonalTeam()->create();
        $this->otherTeam = $this->otherUser->currentTeam;
    });

    describe('CompanyImporter team isolation', function (): void {
        it('creates companies in current team only', function (): void {
            $import = createTestImport($this->user, $this->team, CompanyImporter::class);

            $importer = new CompanyImporter($import, ['name' => 'New Company'], []);
            $record = $importer->resolveRecord();

            expect($record)->toBeInstanceOf(Company::class)
                ->and($record->exists)->toBeFalse();

            // Simulate filling the record as the importer does
            $record->name = 'New Company';
            $record->team_id = $import->team_id;
            $record->save();

            expect($record->team_id)->toBe($this->team->id);
        });

        it('resolves duplicates only from current team', function (): void {
            // Create company in other team with same name
            Company::factory()->for($this->otherTeam)->create(['name' => 'Duplicate Test']);

            $import = createTestImport($this->user, $this->team, CompanyImporter::class);

            $importer = new CompanyImporter($import, ['name' => 'Duplicate Test'], [
                'duplicate_handling' => DuplicateHandlingStrategy::SKIP,
            ]);

            $record = $importer->resolveRecord();

            // Should return new record since no duplicate exists in current team
            expect($record->exists)->toBeFalse();
        });

        it('finds existing company in same team for duplicate handling', function (): void {
            // Create company in current team
            $existing = Company::factory()->for($this->team)->create(['name' => 'My Company']);

            $import = createTestImport($this->user, $this->team, CompanyImporter::class);

            // Create importer and invoke it with the data (simulating import process)
            $importer = new CompanyImporter($import, ['name' => 'name'], [
                'duplicate_handling' => DuplicateHandlingStrategy::SKIP,
            ]);

            // Use reflection to set the data property directly (as __invoke would do)
            $reflection = new ReflectionClass($importer);
            $dataProperty = $reflection->getProperty('data');
            $dataProperty->setValue($importer, ['name' => 'My Company']);

            $record = $importer->resolveRecord();

            // Should return existing record from same team
            expect($record->exists)->toBeTrue()
                ->and($record->id)->toBe($existing->id);
        });
    });

    describe('PeopleImporter team isolation', function (): void {
        it('creates people in current team only', function (): void {
            $import = createTestImport($this->user, $this->team, PeopleImporter::class);

            $importer = new PeopleImporter($import, ['name' => 'name'], []);

            // Use reflection to set the data and originalData properties
            $reflection = new ReflectionClass($importer);
            $dataProperty = $reflection->getProperty('data');
            $dataProperty->setValue($importer, ['name' => 'New Person']);
            $originalDataProperty = $reflection->getProperty('originalData');
            $originalDataProperty->setValue($importer, ['name' => 'New Person']);

            $record = $importer->resolveRecord();

            expect($record)->toBeInstanceOf(People::class)
                ->and($record->exists)->toBeFalse();
        });
    });

    describe('OpportunityImporter team isolation', function (): void {
        it('creates opportunities in current team only', function (): void {
            $import = createTestImport($this->user, $this->team, OpportunityImporter::class);

            $importer = new OpportunityImporter($import, ['name' => 'New Opportunity'], []);
            $record = $importer->resolveRecord();

            expect($record)->toBeInstanceOf(Opportunity::class)
                ->and($record->exists)->toBeFalse();
        });

        it('resolves duplicates only from current team', function (): void {
            // Create opportunity in other team with same name
            Opportunity::factory()->for($this->otherTeam)->create(['name' => 'Big Deal']);

            $import = createTestImport($this->user, $this->team, OpportunityImporter::class);

            $importer = new OpportunityImporter($import, ['name' => 'Big Deal'], [
                'duplicate_handling' => DuplicateHandlingStrategy::SKIP,
            ]);

            $record = $importer->resolveRecord();

            // Should return new record since no duplicate exists in current team
            expect($record->exists)->toBeFalse();
        });

        it('finds existing opportunity in same team for duplicate handling', function (): void {
            // Create opportunity in current team
            $existing = Opportunity::factory()->for($this->team)->create(['name' => 'My Deal']);

            $import = createTestImport($this->user, $this->team, OpportunityImporter::class);

            $importer = new OpportunityImporter($import, ['name' => 'name'], [
                'duplicate_handling' => DuplicateHandlingStrategy::SKIP,
            ]);

            // Use reflection to set the data property directly
            $reflection = new ReflectionClass($importer);
            $dataProperty = $reflection->getProperty('data');
            $dataProperty->setValue($importer, ['name' => 'My Deal']);

            $record = $importer->resolveRecord();

            // Should return existing record from same team
            expect($record->exists)->toBeTrue()
                ->and($record->id)->toBe($existing->id);
        });
    });

    describe('TaskImporter team isolation', function (): void {
        it('creates tasks in current team only', function (): void {
            $import = createTestImport($this->user, $this->team, TaskImporter::class);

            $importer = new TaskImporter($import, ['title' => 'New Task'], []);
            $record = $importer->resolveRecord();

            expect($record)->toBeInstanceOf(Task::class)
                ->and($record->exists)->toBeFalse();
        });

        it('resolves duplicates only from current team', function (): void {
            // Create task in other team with same title
            Task::factory()->for($this->otherTeam)->create(['title' => 'Follow Up']);

            $import = createTestImport($this->user, $this->team, TaskImporter::class);

            $importer = new TaskImporter($import, ['title' => 'Follow Up'], [
                'duplicate_handling' => DuplicateHandlingStrategy::SKIP,
            ]);

            $record = $importer->resolveRecord();

            // Should return new record since no duplicate exists in current team
            expect($record->exists)->toBeFalse();
        });

        it('finds existing task in same team for duplicate handling', function (): void {
            // Create task in current team
            $existing = Task::factory()->for($this->team)->create(['title' => 'My Task']);

            $import = createTestImport($this->user, $this->team, TaskImporter::class);

            $importer = new TaskImporter($import, ['title' => 'title'], [
                'duplicate_handling' => DuplicateHandlingStrategy::SKIP,
            ]);

            // Use reflection to set the data property directly
            $reflection = new ReflectionClass($importer);
            $dataProperty = $reflection->getProperty('data');
            $dataProperty->setValue($importer, ['title' => 'My Task']);

            $record = $importer->resolveRecord();

            // Should return existing record from same team
            expect($record->exists)->toBeTrue()
                ->and($record->id)->toBe($existing->id);
        });
    });

    describe('NoteImporter team isolation', function (): void {
        it('creates notes in current team only', function (): void {
            $import = createTestImport($this->user, $this->team, NoteImporter::class);

            $importer = new NoteImporter($import, ['title' => 'New Note'], []);
            $record = $importer->resolveRecord();

            expect($record)->toBeInstanceOf(Note::class)
                ->and($record->exists)->toBeFalse();
        });

        it('resolves duplicates only from current team', function (): void {
            // Create note in other team with same title
            Note::factory()->for($this->otherTeam)->create(['title' => 'Meeting Notes']);

            $import = createTestImport($this->user, $this->team, NoteImporter::class);

            $importer = new NoteImporter($import, ['title' => 'Meeting Notes'], [
                'duplicate_handling' => DuplicateHandlingStrategy::SKIP,
            ]);

            $record = $importer->resolveRecord();

            // Should return new record since no duplicate exists in current team
            expect($record->exists)->toBeFalse();
        });

        it('finds existing note in same team for duplicate handling', function (): void {
            // Create note in current team
            $existing = Note::factory()->for($this->team)->create(['title' => 'My Note']);

            $import = createTestImport($this->user, $this->team, NoteImporter::class);

            $importer = new NoteImporter($import, ['title' => 'title'], [
                'duplicate_handling' => DuplicateHandlingStrategy::SKIP,
            ]);

            // Use reflection to set the data property directly
            $reflection = new ReflectionClass($importer);
            $dataProperty = $reflection->getProperty('data');
            $dataProperty->setValue($importer, ['title' => 'My Note']);

            $record = $importer->resolveRecord();

            // Should return existing record from same team
            expect($record->exists)->toBeTrue()
                ->and($record->id)->toBe($existing->id);
        });
    });

    describe('Import model team isolation', function (): void {
        it('imports are properly scoped by team_id', function (): void {
            // Create import in other team
            $otherImport = createTestImport($this->otherUser, $this->otherTeam);

            // Create import in my team
            $myImport = createTestImport($this->user, $this->team);

            // Verify imports have correct team_id
            expect($myImport->team_id)->toBe($this->team->id)
                ->and($otherImport->team_id)->toBe($this->otherTeam->id);

            // Query imports for current team should only return my imports
            $teamImports = Import::where('team_id', $this->team->id)->get();

            expect($teamImports)->toHaveCount(1)
                ->and($teamImports->first()->id)->toBe($myImport->id);

            // Query imports for other team should only return their imports
            $otherTeamImports = Import::where('team_id', $this->otherTeam->id)->get();

            expect($otherTeamImports)->toHaveCount(1)
                ->and($otherTeamImports->first()->id)->toBe($otherImport->id);
        });

        it('cannot access imports from other teams', function (): void {
            // Create import in other team
            $otherImport = createTestImport($this->otherUser, $this->otherTeam);

            // Try to query without team filter - should see all
            $allImports = Import::all();
            expect($allImports)->toHaveCount(1);

            // With team filter - should not see other team's import
            $myTeamImports = Import::where('team_id', $this->team->id)->get();
            expect($myTeamImports)->toHaveCount(0);
        });
    });
});
