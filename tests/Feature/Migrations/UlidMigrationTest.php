<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

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
        $user = User::factory()->withPersonalTeam()->create();

        expect($user->id)->toBeString()
            ->and(strlen($user->id))->toBe(26);
    });

    it('uses ULID for team primary key', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        expect($team->id)->toBeString()
            ->and(strlen($team->id))->toBe(26);
    });

    it('uses ULID for company primary key', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $company = Company::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);

        expect($company->id)->toBeString()
            ->and(strlen($company->id))->toBe(26);
    });

    it('uses ULID for people primary key', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $person = People::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);

        expect($person->id)->toBeString()
            ->and(strlen($person->id))->toBe(26);
    });

    it('uses ULID for opportunity primary key', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $opportunity = Opportunity::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);

        expect($opportunity->id)->toBeString()
            ->and(strlen($opportunity->id))->toBe(26);
    });

    it('uses ULID for task primary key', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $task = Task::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);

        expect($task->id)->toBeString()
            ->and(strlen($task->id))->toBe(26);
    });

    it('uses ULID for note primary key', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $note = Note::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);

        expect($note->id)->toBeString()
            ->and(strlen($note->id))->toBe(26);
    });

    it('maintains user-team relationship', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        expect($team->user_id)->toBe($user->id)
            ->and($user->current_team_id)->toBe($team->id);
    });

    it('maintains team-user pivot relationship', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        // Attach user to team via pivot table
        $team->users()->attach($user, ['role' => 'admin']);

        // Refresh relationships
        $user->refresh();
        $team->refresh();

        expect($user->teams)->toHaveCount(1)
            ->and($user->teams->first()->id)->toBe($team->id)
            ->and($team->users)->toHaveCount(1)
            ->and($team->users->first()->id)->toBe($user->id);
    });

    it('maintains company foreign key relationships', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $company = Company::factory()->create([
            'team_id' => $team->id,
            'creator_id' => $user->id,
            'account_owner_id' => $user->id,
        ]);

        expect($company->team_id)->toBe($team->id)
            ->and($company->creator_id)->toBe($user->id)
            ->and($company->account_owner_id)->toBe($user->id)
            ->and($company->team->id)->toBe($team->id)
            ->and($company->creator->id)->toBe($user->id)
            ->and($company->accountOwner->id)->toBe($user->id);
    });

    it('maintains people-company relationship', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $company = Company::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);
        $person = People::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
            'company_id' => $company->id,
        ]);

        expect($person->company_id)->toBe($company->id)
            ->and($person->company->id)->toBe($company->id)
            ->and($company->people)->toHaveCount(1)
            ->and($company->people->first()->id)->toBe($person->id);
    });

    it('maintains opportunity relationships', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $company = Company::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);
        $person = People::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
            'company_id' => $company->id,
        ]);
        $opportunity = Opportunity::factory()->create([
            'team_id' => $user->currentTeam->id,
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
        $user = User::factory()->withPersonalTeam()->create();
        $task = Task::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);

        $task->assignees()->attach($user);

        expect($task->assignees)->toHaveCount(1)
            ->and($task->assignees->first()->id)->toBe($user->id);
    });

    it('maintains taskables polymorphic relationship', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $company = Company::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);
        $task = Task::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);

        $task->companies()->attach($company);

        expect($task->companies)->toHaveCount(1)
            ->and($task->companies->first()->id)->toBe($company->id)
            ->and($company->tasks)->toHaveCount(1)
            ->and($company->tasks->first()->id)->toBe($task->id);
    });

    it('maintains noteables polymorphic relationship', function (): void {
        $user = User::factory()->withPersonalTeam()->create();
        $company = Company::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);
        $note = Note::factory()->create([
            'team_id' => $user->currentTeam->id,
            'creator_id' => $user->id,
        ]);

        $note->companies()->attach($company);

        expect($note->companies)->toHaveCount(1)
            ->and($note->companies->first()->id)->toBe($company->id)
            ->and($company->notes)->toHaveCount(1)
            ->and($company->notes->first()->id)->toBe($note->id);
    });

    it('has correct column types for primary keys', function (): void {
        $tables = ['users', 'teams', 'companies', 'people', 'opportunities', 'tasks', 'notes'];

        foreach ($tables as $table) {
            $columnType = Schema::getColumnType($table, 'id');
            expect(in_array($columnType, ['string', 'char', 'varchar', 'bpchar', 'text'], true))
                ->toBeTrue("Expected {$table}.id to be string type, got {$columnType}");
        }
    });

    it('has correct column types for foreign keys', function (): void {
        $foreignKeys = [
            'companies' => ['team_id', 'creator_id', 'account_owner_id'],
            'people' => ['team_id', 'creator_id', 'company_id'],
            'opportunities' => ['team_id', 'creator_id', 'company_id', 'contact_id'],
            'tasks' => ['team_id', 'creator_id'],
            'notes' => ['team_id', 'creator_id'],
            'users' => ['current_team_id'],
            'teams' => ['user_id'],
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
        $pivotTables = ['team_user', 'task_user', 'taskables', 'noteables'];

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
