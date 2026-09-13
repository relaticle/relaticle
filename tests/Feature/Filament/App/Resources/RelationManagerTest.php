<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\NotesRelationManager as CompanyNotesRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\PeopleRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\TasksRelationManager as CompanyTasksRelationManager;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\OpportunityResource\RelationManagers\NotesRelationManager as OpportunityNotesRelationManager;
use App\Filament\Resources\OpportunityResource\RelationManagers\TasksRelationManager as OpportunityTasksRelationManager;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\NotesRelationManager as PeopleNotesRelationManager;
use App\Filament\Resources\PeopleResource\RelationManagers\TasksRelationManager as PeopleTasksRelationManager;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

/**
 * Filament renders per-row Edit/Delete actions, which authorize each record
 * through its policy. Relation managers query the relationship directly, so
 * nothing eager-loads the record's `workspace`. Two or more rows arm Eloquent's
 * strict lazy-loading guard (Builder::hydrate only sets it for multi-row
 * results), so a policy that resolves `$record->workspace` throws there while a
 * single row silently passes.
 */
it('renders the :dataset relation manager with multiple records', function (string $relationManager, Closure $setUp): void {
    [$ownerRecord, $pageClass] = $setUp($this->user, $this->workspace);

    livewire($relationManager, [
        'ownerRecord' => $ownerRecord,
        'pageClass' => $pageClass,
    ])->assertOk();
})->with([
    'company people' => [
        PeopleRelationManager::class,
        function (User $user, $workspace): array {
            $company = Company::factory()->recycle([$user, $workspace])->create();
            People::factory(4)->recycle([$user, $workspace])->create(['company_id' => $company->getKey()]);

            return [$company, ViewCompany::class];
        },
    ],
    'company notes' => [
        CompanyNotesRelationManager::class,
        function (User $user, $workspace): array {
            $company = Company::factory()->recycle([$user, $workspace])->create();
            $company->notes()->saveMany(Note::factory(4)->recycle([$user, $workspace])->make());

            return [$company, ViewCompany::class];
        },
    ],
    'company tasks' => [
        CompanyTasksRelationManager::class,
        function (User $user, $workspace): array {
            $company = Company::factory()->recycle([$user, $workspace])->create();
            $company->tasks()->saveMany(Task::factory(4)->recycle([$user, $workspace])->make());

            return [$company, ViewCompany::class];
        },
    ],
    'opportunity notes' => [
        OpportunityNotesRelationManager::class,
        function (User $user, $workspace): array {
            $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create();
            $opportunity->notes()->saveMany(Note::factory(4)->recycle([$user, $workspace])->make());

            return [$opportunity, ViewOpportunity::class];
        },
    ],
    'opportunity tasks' => [
        OpportunityTasksRelationManager::class,
        function (User $user, $workspace): array {
            $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create();
            $opportunity->tasks()->saveMany(Task::factory(4)->recycle([$user, $workspace])->make());

            return [$opportunity, ViewOpportunity::class];
        },
    ],
    'people notes' => [
        PeopleNotesRelationManager::class,
        function (User $user, $workspace): array {
            $people = People::factory()->recycle([$user, $workspace])->create();
            $people->notes()->saveMany(Note::factory(4)->recycle([$user, $workspace])->make());

            return [$people, ViewPeople::class];
        },
    ],
    'people tasks' => [
        PeopleTasksRelationManager::class,
        function (User $user, $workspace): array {
            $people = People::factory()->recycle([$user, $workspace])->create();
            $people->tasks()->saveMany(Task::factory(4)->recycle([$user, $workspace])->make());

            return [$people, ViewPeople::class];
        },
    ],
]);
