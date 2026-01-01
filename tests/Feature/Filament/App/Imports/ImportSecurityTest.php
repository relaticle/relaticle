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

describe('Import Security - Team Isolation', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->withPersonalTeam()->create();
        $this->team = $this->user->currentTeam;
        $this->actingAs($this->user);
        Filament::setTenant($this->team);

        $this->otherUser = User::factory()->withPersonalTeam()->create();
        $this->otherTeam = $this->otherUser->currentTeam;
    });

    it('creates :dataset records in current team only', function (string $importerClass, string $modelClass, array $columnMap): void {
        $import = createImportRecord($this->user, $this->team, $importerClass);
        $importer = new $importerClass($import, $columnMap, []);
        $record = $importer->resolveRecord();

        expect($record)->toBeInstanceOf($modelClass)
            ->and($record->exists)->toBeFalse();
    })->with([
        'companies' => [CompanyImporter::class, Company::class, ['name' => 'New Company']],
        'opportunities' => [OpportunityImporter::class, Opportunity::class, ['name' => 'New Opportunity']],
        'tasks' => [TaskImporter::class, Task::class, ['title' => 'New Task']],
        'notes' => [NoteImporter::class, Note::class, ['title' => 'New Note']],
    ]);

    it('resolves :dataset duplicates only from current team', function (
        string $importerClass,
        string $modelClass,
        string $nameField,
        string $testValue
    ): void {
        // Create record in other team
        $modelClass::factory()->for($this->otherTeam)->create([$nameField => $testValue]);

        $import = createImportRecord($this->user, $this->team, $importerClass);
        $importer = new $importerClass($import, [$nameField => $testValue], [
            'duplicate_handling' => DuplicateHandlingStrategy::SKIP,
        ]);
        $record = $importer->resolveRecord();

        // Should return new record since no duplicate exists in current team
        expect($record->exists)->toBeFalse();
    })->with([
        'companies' => [CompanyImporter::class, Company::class, 'name', 'Duplicate Test'],
        'opportunities' => [OpportunityImporter::class, Opportunity::class, 'name', 'Big Deal'],
        'tasks' => [TaskImporter::class, Task::class, 'title', 'Follow Up'],
        'notes' => [NoteImporter::class, Note::class, 'title', 'Meeting Notes'],
    ]);

    it('creates people in current team only', function (): void {
        $import = createImportRecord($this->user, $this->team, PeopleImporter::class);
        $importer = new PeopleImporter($import, ['name' => 'name'], []);

        $reflection = new ReflectionClass($importer);
        $dataProperty = $reflection->getProperty('data');
        $dataProperty->setValue($importer, ['name' => 'New Person']);
        $originalDataProperty = $reflection->getProperty('originalData');
        $originalDataProperty->setValue($importer, ['name' => 'New Person']);

        $record = $importer->resolveRecord();

        expect($record)->toBeInstanceOf(People::class)
            ->and($record->exists)->toBeFalse();
    });

    describe('Import model team isolation', function (): void {
        it('imports are properly scoped by team_id', function (): void {
            $otherImport = createImportRecord($this->otherUser, $this->otherTeam);
            $myImport = createImportRecord($this->user, $this->team);

            expect($myImport->team_id)->toBe($this->team->id)
                ->and($otherImport->team_id)->toBe($this->otherTeam->id);

            $teamImports = Import::where('team_id', $this->team->id)->get();
            expect($teamImports)->toHaveCount(1)
                ->and($teamImports->first()->id)->toBe($myImport->id);
        });

        it('cannot access imports from other teams', function (): void {
            createImportRecord($this->otherUser, $this->otherTeam);

            $myTeamImports = Import::where('team_id', $this->team->id)->get();
            expect($myTeamImports)->toHaveCount(0);
        });
    });
});
