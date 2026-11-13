<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\NotesRelationManager as CompanyNotesRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\TasksRelationManager as CompanyTasksRelationManager;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\OpportunityResource\RelationManagers\NotesRelationManager as OpportunityNotesRelationManager;
use App\Filament\Resources\OpportunityResource\RelationManagers\TasksRelationManager as OpportunityTasksRelationManager;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
});

function actAsRole(object $test, WorkspaceRole $role): User
{
    $user = User::factory()->create(['current_workspace_id' => $test->workspace->getKey()]);
    $test->workspace->users()->attach($user, ['role' => $role->value]);

    $test->actingAs($user);
    Filament::setTenant($test->workspace);

    return $user;
}

dataset('pivot relation managers', [
    'company tasks' => [CompanyTasksRelationManager::class, ViewCompany::class, Company::class, Task::class, 'tasks'],
    'company notes' => [CompanyNotesRelationManager::class, ViewCompany::class, Company::class, Note::class, 'notes'],
    'opportunity tasks' => [OpportunityTasksRelationManager::class, ViewOpportunity::class, Opportunity::class, Task::class, 'tasks'],
    'opportunity notes' => [OpportunityNotesRelationManager::class, ViewOpportunity::class, Opportunity::class, Note::class, 'notes'],
]);

function pivotFixture(object $test, string $ownerModel, string $relatedModel, string $relation, bool $attached): array
{
    $owner = $ownerModel::factory()->recycle([$test->owner, $test->workspace])->create();
    $related = $relatedModel::factory()->recycle([$test->owner, $test->workspace])->create();

    if ($attached) {
        $owner->{$relation}()->attach($related);
    }

    return [$owner, $related];
}

it('hides attach and refuses it for a viewer on the :dataset relation manager', function (string $manager, string $page, string $ownerModel, string $relatedModel, string $relation): void {
    [$owner, $related] = pivotFixture($this, $ownerModel, $relatedModel, $relation, false);
    actAsRole($this, WorkspaceRole::Viewer);

    livewire($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
        ->assertActionHidden(TestAction::make('attach')->table())
        ->call('mountAction', 'attach', [], ['table' => true])
        ->call('callMountedAction');

    expect($owner->{$relation}()->whereKey($related->getKey())->exists())->toBeFalse();
})->with('pivot relation managers');

it('hides detach and refuses it for a viewer on the :dataset relation manager', function (string $manager, string $page, string $ownerModel, string $relatedModel, string $relation): void {
    [$owner, $related] = pivotFixture($this, $ownerModel, $relatedModel, $relation, true);
    actAsRole($this, WorkspaceRole::Viewer);

    livewire($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
        ->assertActionHidden(TestAction::make('detach')->table($related))
        ->call('mountAction', 'detach', [], ['table' => true, 'recordKey' => $related->getKey()])
        ->call('callMountedAction');

    expect($owner->{$relation}()->whereKey($related->getKey())->exists())->toBeTrue();
})->with('pivot relation managers');

it('refuses bulk detach for a viewer on the :dataset relation manager', function (string $manager, string $page, string $ownerModel, string $relatedModel, string $relation): void {
    [$owner, $related] = pivotFixture($this, $ownerModel, $relatedModel, $relation, true);
    actAsRole($this, WorkspaceRole::Viewer);

    livewire($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
        ->selectTableRecords([$related->getKey()])
        ->assertActionHidden(TestAction::make('detach')->table()->bulk())
        ->call('mountAction', 'detach', [], ['table' => true, 'bulk' => true])
        ->call('callMountedAction');

    expect($owner->{$relation}()->whereKey($related->getKey())->exists())->toBeTrue();
})->with([
    'company notes' => [CompanyNotesRelationManager::class, ViewCompany::class, Company::class, Note::class, 'notes'],
    'opportunity tasks' => [OpportunityTasksRelationManager::class, ViewOpportunity::class, Opportunity::class, Task::class, 'tasks'],
    'opportunity notes' => [OpportunityNotesRelationManager::class, ViewOpportunity::class, Opportunity::class, Note::class, 'notes'],
]);

it('lets a member attach and detach on the :dataset relation manager', function (string $manager, string $page, string $ownerModel, string $relatedModel, string $relation): void {
    [$owner, $attachable] = pivotFixture($this, $ownerModel, $relatedModel, $relation, false);
    $linked = $relatedModel::factory()->recycle([$this->owner, $this->workspace])->create();
    $owner->{$relation}()->attach($linked);
    actAsRole($this, WorkspaceRole::Member);

    $component = livewire($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
        ->assertActionVisible(TestAction::make('attach')->table())
        ->assertActionVisible(TestAction::make('detach')->table($linked));

    $component->callAction(TestAction::make('attach')->table(), data: ['recordId' => $attachable->getKey()]);
    $component->callAction(TestAction::make('detach')->table($linked));

    expect($owner->{$relation}()->whereKey($attachable->getKey())->exists())->toBeTrue()
        ->and($owner->{$relation}()->whereKey($linked->getKey())->exists())->toBeFalse();
})->with('pivot relation managers');

it('lets a member bulk detach on the :dataset relation manager', function (string $manager, string $page, string $ownerModel, string $relatedModel, string $relation): void {
    [$owner, $first] = pivotFixture($this, $ownerModel, $relatedModel, $relation, true);
    $second = $relatedModel::factory()->recycle([$this->owner, $this->workspace])->create();
    $kept = $relatedModel::factory()->recycle([$this->owner, $this->workspace])->create();
    $owner->{$relation}()->attach([$second->getKey(), $kept->getKey()]);
    actAsRole($this, WorkspaceRole::Member);

    livewire($manager, ['ownerRecord' => $owner, 'pageClass' => $page])
        ->selectTableRecords([$first->getKey(), $second->getKey()])
        ->callAction(TestAction::make('detach')->table()->bulk());

    expect($owner->{$relation}()->pluck($owner->{$relation}()->getRelated()->getQualifiedKeyName())->all())->toBe([$kept->getKey()])
        ->and($relatedModel::query()->whereKey([$first->getKey(), $second->getKey()])->count())->toBe(2);
})->with([
    'company notes' => [CompanyNotesRelationManager::class, ViewCompany::class, Company::class, Note::class, 'notes'],
    'opportunity tasks' => [OpportunityTasksRelationManager::class, ViewOpportunity::class, Opportunity::class, Task::class, 'tasks'],
    'opportunity notes' => [OpportunityNotesRelationManager::class, ViewOpportunity::class, Opportunity::class, Note::class, 'notes'],
]);
