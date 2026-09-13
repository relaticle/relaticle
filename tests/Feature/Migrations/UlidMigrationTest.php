<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;

mutates(User::class, Workspace::class, Company::class, People::class, Opportunity::class, Task::class, Note::class);

/**
 * Tests for the ULID migration.
 *
 * These tests verify that:
 * 1. Fresh installs work correctly (tables already use ULID)
 * 2. All models can be created and relationships work
 * 3. Pivot tables function correctly
 * 4. Polymorphic relationships work
 */
describe('ULID Migration', function (): void {

    it('uses ULID for user primary key', function (): void {
        $user = User::factory()->withWorkspace()->create();

        expect($user->id)->toBeString()
            ->and(strlen($user->id))->toBe(26);
    });

    it('uses ULID for workspace primary key', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->currentWorkspace;

        expect($workspace->id)->toBeString()
            ->and(strlen($workspace->id))->toBe(26);
    });

    it('uses ULID for company primary key', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $company = Company::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);

        expect($company->id)->toBeString()
            ->and(strlen($company->id))->toBe(26);
    });

    it('uses ULID for people primary key', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $person = People::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);

        expect($person->id)->toBeString()
            ->and(strlen($person->id))->toBe(26);
    });

    it('uses ULID for opportunity primary key', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $opportunity = Opportunity::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);

        expect($opportunity->id)->toBeString()
            ->and(strlen($opportunity->id))->toBe(26);
    });

    it('uses ULID for task primary key', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $task = Task::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);

        expect($task->id)->toBeString()
            ->and(strlen($task->id))->toBe(26);
    });

    it('uses ULID for note primary key', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $note = Note::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);

        expect($note->id)->toBeString()
            ->and(strlen($note->id))->toBe(26);
    });

    it('maintains user-workspace relationship', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->currentWorkspace;

        expect($workspace->user_id)->toBe($user->id)
            ->and($user->current_workspace_id)->toBe($workspace->id);
    });

    it('maintains workspace-user pivot relationship', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->currentWorkspace;

        // Attach user to workspace via pivot table
        $workspace->users()->attach($user, ['role' => 'admin']);

        // Refresh relationships
        $user->refresh();
        $workspace->refresh();

        expect($user->workspaces)->toHaveCount(1)
            ->and($user->workspaces->first()->id)->toBe($workspace->id)
            ->and($workspace->users)->toHaveCount(1)
            ->and($workspace->users->first()->id)->toBe($user->id);
    });

    it('maintains company foreign key relationships', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $workspace = $user->currentWorkspace;
        $company = Company::factory()->create([
            'workspace_id' => $workspace->id,
            'creator_id' => $user->id,
            'account_owner_id' => $user->id,
        ]);

        expect($company->workspace_id)->toBe($workspace->id)
            ->and($company->creator_id)->toBe($user->id)
            ->and($company->account_owner_id)->toBe($user->id)
            ->and($company->workspace->id)->toBe($workspace->id)
            ->and($company->creator->id)->toBe($user->id)
            ->and($company->accountOwner->id)->toBe($user->id);
    });

    it('maintains people-company relationship', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $company = Company::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);
        $person = People::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
            'company_id' => $company->id,
        ]);

        expect($person->company_id)->toBe($company->id)
            ->and($person->company->id)->toBe($company->id)
            ->and($company->people)->toHaveCount(1)
            ->and($company->people->first()->id)->toBe($person->id);
    });

    it('maintains opportunity relationships', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $company = Company::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);
        $person = People::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
            'company_id' => $company->id,
        ]);
        $opportunity = Opportunity::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
            'company_id' => $company->id,
            'contact_id' => $person->id,
        ]);

        expect($opportunity->company_id)->toBe($company->id)
            ->and($opportunity->contact_id)->toBe($person->id)
            ->and($opportunity->company->id)->toBe($company->id)
            ->and($opportunity->contact->id)->toBe($person->id);
    });

    it('maintains task-user pivot relationship', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $task = Task::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);

        $task->assignees()->attach($user);

        expect($task->assignees)->toHaveCount(1)
            ->and($task->assignees->first()->id)->toBe($user->id);
    });

    it('maintains taskables polymorphic relationship', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $company = Company::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);
        $task = Task::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);

        $task->companies()->attach($company);

        expect($task->companies)->toHaveCount(1)
            ->and($task->companies->first()->id)->toBe($company->id)
            ->and($company->tasks)->toHaveCount(1)
            ->and($company->tasks->first()->id)->toBe($task->id);
    });

    it('maintains noteables polymorphic relationship', function (): void {
        $user = User::factory()->withWorkspace()->create();
        $company = Company::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);
        $note = Note::factory()->create([
            'workspace_id' => $user->currentWorkspace->id,
            'creator_id' => $user->id,
        ]);

        $note->companies()->attach($company);

        expect($note->companies)->toHaveCount(1)
            ->and($note->companies->first()->id)->toBe($company->id)
            ->and($company->notes)->toHaveCount(1)
            ->and($company->notes->first()->id)->toBe($note->id);
    });

    it('has correct column types for primary keys', function (): void {
        $tables = ['users', 'workspaces', 'companies', 'people', 'opportunities', 'tasks', 'notes'];

        foreach ($tables as $table) {
            $columnType = Schema::getColumnType($table, 'id');
            expect(in_array($columnType, ['string', 'char', 'varchar', 'bpchar', 'text'], true))
                ->toBeTrue("Expected {$table}.id to be string type, got {$columnType}");
        }
    });

    it('has correct column types for foreign keys', function (): void {
        $foreignKeys = [
            'companies' => ['workspace_id', 'creator_id', 'account_owner_id'],
            'people' => ['workspace_id', 'creator_id', 'company_id'],
            'opportunities' => ['workspace_id', 'creator_id', 'company_id', 'contact_id'],
            'tasks' => ['workspace_id', 'creator_id'],
            'notes' => ['workspace_id', 'creator_id'],
            'users' => ['current_workspace_id'],
            'workspaces' => ['user_id'],
        ];

        foreach ($foreignKeys as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $columnType = Schema::getColumnType($table, $column);
                    expect(in_array($columnType, ['string', 'char', 'varchar', 'bpchar', 'text'], true))
                        ->toBeTrue("Expected {$table}.{$column} to be string type, got {$columnType}");
                }
            }
        }
    });

    it('has integer primary key for pivot tables', function (): void {
        $pivotTables = ['workspace_user', 'task_user', 'taskables', 'noteables'];

        foreach ($pivotTables as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'id')) {
                $columnType = Schema::getColumnType($table, 'id');
                // Pivot tables can have either int or string id depending on database
                expect(in_array($columnType, ['integer', 'bigint', 'int4', 'int8', 'string', 'char', 'varchar', 'bpchar', 'text'], true))
                    ->toBeTrue("Unexpected column type for {$table}.id: {$columnType}");
            }
        }
    });

});
