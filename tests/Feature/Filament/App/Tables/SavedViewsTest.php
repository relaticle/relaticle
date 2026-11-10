<?php

declare(strict_types=1);

use App\Actions\Views\CreateSavedView;
use App\Actions\Views\DeleteSavedView;
use App\Actions\Views\EnsureSavedViewNameIsFree;
use App\Actions\Views\SetDefaultSavedView;
use App\Actions\Views\UpdateSavedView;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\Notifications\SavedViewChange;
use App\Enums\SavedViewType;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\HasSavedViews;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Models\Notification;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\User;
use App\Notifications\SavedViewChangedNotification;
use App\Policies\SavedViewPolicy;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(
    HasSavedViews::class,
    PersistsTableState::class,
    SavedViewPolicy::class,
    CreateSavedView::class,
    UpdateSavedView::class,
    DeleteSavedView::class,
    SetDefaultSavedView::class,
    EnsureSavedViewNameIsFree::class,
);

dataset('view list pages', [
    'companies' => [ListCompanies::class, CrmEntity::Company],
    'people' => [ListPeople::class, CrmEntity::People],
    'opportunities' => [ListOpportunities::class, CrmEntity::Opportunity],
    'tasks' => [ManageTasks::class, CrmEntity::Task],
    'notes' => [ManageNotes::class, CrmEntity::Note],
]);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();
});

function savedViewMember(WorkspaceRole $role): User
{
    $member = User::factory()->create(['current_workspace_id' => test()->workspace->getKey()]);
    test()->workspace->users()->attach($member, ['role' => $role->value]);

    return $member;
}

function viewFor(User $owner, array $attributes = []): SavedView
{
    return SavedView::factory()->for($owner)->for(test()->workspace)->create($attributes);
}

function hotLeads(): TableStateData
{
    return new TableStateData(
        columns: [['type' => 'column', 'name' => 'created_at', 'isToggled' => false]],
        sort: 'created_at:desc',
        filters: ['trashed' => ['value' => 0]],
        search: 'acme',
        perPage: 50,
    );
}

function preferenceRow(): ?TablePreference
{
    return TablePreference::query()->withoutGlobalScopes()->first();
}

it('applies columns, sort, filters, search and page size in one call', function (string $page, CrmEntity $entity): void {
    $view = viewFor($this->user, ['entity' => $entity, 'state' => hotLeads()]);

    $component = livewire($page)->call('applySavedView', $view->getKey());

    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->get('tableSearch'))->toBe('acme')
        ->and((int) $component->get('tableRecordsPerPage'))->toBe(50)
        ->and($component->get('tableFilters.trashed.value'))->toEqual(0)
        ->and($component->instance()->getTableFiltersForm()->getRawState()['trashed']['value'])->toEqual(0)
        ->and($component->get('tableColumns.0.name'))->toBe('created_at')
        ->and($component->get('tableColumns.0.isToggled'))->toBeFalse()
        ->and($component->instance()->isSavedViewModified())->toBeFalse()
        ->and(preferenceRow()->active_view_id)->toBe($view->getKey());
})->with('view list pages');

it('falls back to the default page size when a view holds one the table does not offer', function (): void {
    $view = viewFor($this->user, ['state' => new TableStateData(perPage: 200)]);

    expect((int) livewire(ListCompanies::class)->set('tableRecordsPerPage', 50)->call('applySavedView', $view->getKey())->get('tableRecordsPerPage'))->toBe(25);
});

it('applies a sort through the grouping a view names', function (): void {
    $view = viewFor($this->user, ['state' => new TableStateData(sort: 'name:asc')]);

    $component = livewire(ListCompanies::class)->set('tableSearch', 'acme')->set('tableSort', 'created_at:desc')->call('applySavedView', $view->getKey());

    expect($component->get('tableSearch'))->toBeEmpty()
        ->and($component->get('tableSort'))->toBe('name:asc');
});

it('saves the current table as a new view without widths and applies it', function (): void {
    $component = livewire(ListCompanies::class)
        ->set('tableSort', 'created_at:desc')
        ->call('updateResizableColumnWidth', 'created_at', '300')
        ->callAction('saveSavedViewAs', ['name' => 'Hot leads'])
        ->assertHasNoActionErrors()
        ->assertNotified('"Hot leads" saved as a new view');

    $view = SavedView::query()->withoutGlobalScopes()->sole();

    expect($view->name)->toBe('Hot leads')
        ->and($view->type)->toBe(SavedViewType::Table)
        ->and($view->is_shared)->toBeFalse()
        ->and($view->state->sort)->toBe('created_at:desc')
        ->and($view->state->widths)->toBe([])
        ->and($component->get('activeSavedViewId'))->toBe($view->getKey());

    $component->assertSeeText('Hot leads')->assertDontSeeText('(modified)');
});

it('refuses a view name the member already uses, ignoring case', function (): void {
    viewFor($this->user, ['name' => 'Hot leads']);

    livewire(ListCompanies::class)
        ->callAction('saveSavedViewAs', ['name' => 'hot LEADS '])
        ->assertHasActionErrors(['name']);

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('names the owner when an admin renames their view to a name the owner already uses', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $shared = viewFor($owner, ['name' => 'Team', 'is_shared' => true]);
    viewFor($owner, ['name' => 'Private plan']);
    $this->actingAs(savedViewMember(WorkspaceRole::Admin));

    livewire(ListCompanies::class)
        ->callAction('renameSavedView', ['name' => 'private PLAN'], ['view' => $shared->getKey()])
        ->assertHasActionErrors(['name' => "{$owner->name} already has a view with this name."]);

    expect($shared->refresh()->name)->toBe('Team');
});

it('names the owner when the name is taken between the check and the write', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $shared = viewFor($owner, ['name' => 'Team', 'is_shared' => true]);
    $this->actingAs(savedViewMember(WorkspaceRole::Admin));
    SavedView::updating(fn () => viewFor($owner, ['name' => 'Raced']));

    livewire(ListCompanies::class)
        ->callAction('renameSavedView', ['name' => 'Raced'], ['view' => $shared->getKey()])
        ->assertHasActionErrors(['name' => "{$owner->name} already has a view with this name."]);
});

it('lets two members use the same view name', function (): void {
    viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Hot leads', 'is_shared' => true]);

    livewire(ListCompanies::class)->callAction('saveSavedViewAs', ['name' => 'Hot leads'])->assertHasNoActionErrors();

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('marks a changed view as modified, reverts it and saves over it', function (): void {
    $view = viewFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->set('tableSort', 'name:asc');

    expect($component->instance()->isSavedViewModified())->toBeTrue();
    $component->assertSeeText('(modified)')->assertSeeHtml('wire:click="revertSavedView"');

    $component->call('revertSavedView');
    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->instance()->isSavedViewModified())->toBeFalse();

    $component->set('tableSort', 'name:asc')->callAction('saveSavedView');
    expect($view->refresh()->state->sort)->toBe('name:asc')
        ->and($component->instance()->isSavedViewModified())->toBeFalse();
});

it('stays unmodified when the page size is changed and set back from the select', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())
        ->set('tableRecordsPerPage', '100')
        ->set('tableRecordsPerPage', '50');

    $preference = preferenceRow();

    expect($component->instance()->isSavedViewModified())->toBeFalse()
        ->and($preference->state->withoutWidths()->hash())->toBe($preference->applied_view_hash);
});

it('ignores widths when telling whether a view was changed', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('updateResizableColumnWidth', 'created_at', '300');

    expect($component->instance()->isSavedViewModified())->toBeFalse();
});

it('sets a default when another request writes the member\'s first preference row at the same moment', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class);
    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        if ($raced || ! str_starts_with($query->sql, 'select') || ! str_contains($query->sql, 'table_preferences')) {
            return;
        }

        $raced = true;
        TablePreference::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Company]);
    });

    $component->call('toggleDefaultSavedView', $view->getKey())->assertOk();

    expect(TablePreference::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(preferenceRow()->default_view_id)->toBe($view->getKey());
});

it('brings back the last state within a session, and the default view at the start of the next one', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('toggleDefaultSavedView', $view->getKey())->set('tableSort', 'name:asc');

    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('tableSort'))->toBe('name:asc')
        ->and($revisit->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($revisit->instance()->isSavedViewModified())->toBeTrue();

    session()->flush();
    $nextSession = livewire(ListCompanies::class);

    expect($nextSession->get('tableSort'))->toBe('created_at:desc')
        ->and($nextSession->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($nextSession->instance()->isSavedViewModified())->toBeFalse();
});

it('opens a new session on the default view over another applied one, and keeps it for that session', function (): void {
    $default = viewFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $other = viewFor($this->user, ['name' => 'By name', 'state' => new TableStateData(sort: 'name:asc')]);
    livewire(ListCompanies::class)->call('toggleDefaultSavedView', $default->getKey())->call('applySavedView', $other->getKey());

    session()->flush();

    expect(livewire(ListCompanies::class)->get('activeSavedViewId'))->toBe($default->getKey());

    $sameSession = livewire(ListCompanies::class);

    expect($sameSession->get('activeSavedViewId'))->toBe($default->getKey())
        ->and($sameSession->get('tableSort'))->toBe('created_at:desc')
        ->and($sameSession->instance()->isSavedViewModified())->toBeFalse();
});

it('keeps the last state across sessions for a member with no default view', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->set('tableSort', 'name:asc');

    session()->flush();

    expect(livewire(ListCompanies::class)->get('tableSort'))->toBe('name:asc');
});

it('opens on the default view when the member has no saved state, and writes nothing', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    TablePreference::factory()->for($this->user)->for($this->workspace)->create(['default_view_id' => $view->getKey()]);
    $updatedAt = preferenceRow()->updated_at;

    $this->travelTo(now()->addHour());
    $component = livewire(ListCompanies::class);

    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($component->instance()->isSavedViewModified())->toBeFalse()
        ->and(preferenceRow()->updated_at)->toEqual($updatedAt);
});

it('switches back to the standard view from a saved one', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('useStandardTable');

    expect($component->get('tableSort'))->toBeNull()
        ->and($component->get('tableSearch'))->toBeEmpty()
        ->and((int) $component->get('tableRecordsPerPage'))->toBe(25)
        ->and($component->get('activeSavedViewId'))->toBeNull()
        ->and(collect($component->get('tableColumns'))->firstWhere('name', 'created_at')['isToggled'])->toBeTrue();

    session()->flush();

    expect(livewire(ListCompanies::class)->get('tableSort'))->toBeNull();
});

it('offers the standard view and nothing to manage when no view is saved', function (): void {
    livewire(ListCompanies::class)
        ->assertSeeText('All companies')
        ->assertActionHidden('renameSavedView')
        ->assertActionHidden('shareSavedView')
        ->assertActionHidden('deleteSavedView');
});

it('shares a view only after the member confirms', function (): void {
    $view = viewFor($this->user, ['name' => 'Hot leads']);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->mountAction('shareSavedView', ['view' => $view->getKey()]);

    expect($view->refresh()->is_shared)->toBeFalse();
    $component->assertActionMounted('shareSavedView');

    $component->callMountedAction();
    expect($view->refresh()->is_shared)->toBeTrue();

    $component->mountAction('stopSharingSavedView', ['view' => $view->getKey()]);
    expect($view->refresh()->is_shared)->toBeFalse();
});

it('gives a member the new version of a shared view they had not changed', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['is_shared' => true, 'state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey());

    resolve(UpdateSavedView::class)->execute($owner, $view, ['state' => new TableStateData(sort: 'name:asc')]);
    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('tableSort'))->toBe('name:asc')
        ->and($revisit->instance()->isSavedViewModified())->toBeFalse();
});

it('keeps a member\'s changed copy when the shared view changes under it', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['is_shared' => true, 'state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->set('tableSearch', 'mine');

    resolve(UpdateSavedView::class)->execute($owner, $view, ['state' => new TableStateData(sort: 'name:asc')]);
    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('tableSearch'))->toBe('mine')
        ->and($revisit->get('tableSort'))->toBe('created_at:desc')
        ->and($revisit->instance()->isSavedViewModified())->toBeTrue();
});

it('clears other members\' pointers when a view stops being shared, and keeps their table', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['is_shared' => true, 'state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('toggleDefaultSavedView', $view->getKey());

    resolve(UpdateSavedView::class)->execute($owner, $view, ['is_shared' => false]);
    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect(preferenceRow()->only('active_view_id', 'default_view_id', 'applied_view_hash'))->toBe(['active_view_id' => null, 'default_view_id' => null, 'applied_view_hash' => null])
        ->and($revisit->get('tableSort'))->toBe('created_at:desc')
        ->and($revisit->get('activeSavedViewId'))->toBeNull();
});

it('keeps the table and clears the pointers when a view is deleted', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('toggleDefaultSavedView', $view->getKey())
        ->callAction('deleteSavedView', arguments: ['view' => $view->getKey()])
        ->assertSet('activeSavedViewId', null)
        ->assertSet('defaultSavedViewId', null)
        ->assertSet('tableSort', 'created_at:desc');

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(preferenceRow()->active_view_id)->toBeNull();
});

it('confirms a rename the way it confirms every other view change', function (): void {
    $view = viewFor($this->user, ['name' => 'Renewals']);

    livewire(ListCompanies::class)
        ->callAction('renameSavedView', ['name' => 'Renewals due'], ['view' => $view->getKey()])
        ->assertNotified('Renamed to "Renewals due"');
});

it('manages a view from its own row without switching to it', function (): void {
    $active = viewFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $other = viewFor($this->user, ['name' => 'Renewals']);

    livewire(ListCompanies::class)
        ->call('applySavedView', $active->getKey())
        ->callAction('renameSavedView', ['name' => 'Renewals due'], ['view' => $other->getKey()])
        ->call('toggleDefaultSavedView', $other->getKey())
        ->assertSet('defaultSavedViewId', $other->getKey())
        ->callAction('shareSavedView', arguments: ['view' => $other->getKey()])
        ->callAction('deleteSavedView', arguments: ['view' => $other->getKey()])
        ->assertSet('activeSavedViewId', $active->getKey())
        ->assertSet('defaultSavedViewId', null)
        ->assertSet('tableSort', 'created_at:desc');

    expect(SavedView::query()->withoutGlobalScopes()->pluck('name')->all())->toBe(['Hot leads']);
});

it('marks the view the table is showing, and only that one', function (): void {
    $view = viewFor($this->user, ['name' => 'Team pipeline']);

    $component = livewire(ListCompanies::class)
        ->assertSeeHtmlInOrder(['aria-current="true"', 'All companies', 'Team pipeline'])
        ->call('applySavedView', $view->getKey())
        ->assertSeeHtmlInOrder(['All companies', 'aria-current="true"', 'Team pipeline']);

    expect(substr_count($component->html(), 'aria-current="true"'))->toBe(1);
});

it('labels the view that opens by default and the views a member shares', function (): void {
    $shared = viewFor($this->user, ['name' => 'Team pipeline', 'is_shared' => true]);
    viewFor($this->user, ['name' => 'Just me']);

    $component = livewire(ListCompanies::class)
        ->assertSeeHtmlInOrder(['All companies', 'aria-label="Default"', 'Just me', 'Team pipeline', '<span>Shared</span>'])
        ->call('toggleDefaultSavedView', $shared->getKey())
        ->assertSeeHtmlInOrder(['All companies', 'Just me', 'Team pipeline', 'aria-label="Default"', '<span>Shared</span>']);

    expect(substr_count($component->html(), 'aria-label="Default"'))->toBe(1)
        ->and(substr_count($component->html(), '<span>Shared</span>'))->toBe(1);
});

it('lists own views and views shared with the workspace, never someone else\'s private one', function (): void {
    $other = savedViewMember(WorkspaceRole::Member);
    $mine = viewFor($this->user, ['name' => 'Mine']);
    viewFor($other, ['name' => 'Renewals this month', 'is_shared' => true]);
    $private = viewFor($other, ['name' => 'Private plan']);

    livewire(ListCompanies::class)
        ->assertSeeText(['Mine', 'Renewals this month', $other->name])
        ->assertDontSeeText('Private plan')
        ->assertSeeHtml("applySavedView('{$mine->getKey()}')")
        ->call('applySavedView', $private->getKey())
        ->assertNotified('That view is no longer available.')
        ->assertSet('activeSavedViewId', null);
});

it('keeps views apart per table and per workspace', function (): void {
    viewFor($this->user, ['name' => 'People view', 'entity' => CrmEntity::People]);
    SavedView::factory()->for($this->user)->for(User::factory()->withWorkspace()->create()->currentWorkspace)->create(['name' => 'Elsewhere']);

    livewire(ListCompanies::class)->assertDontSeeText(['People view', 'Elsewhere']);
});

it('refuses a view id from another workspace at every entry point', function (): void {
    $stranger = User::factory()->withWorkspace()->create();
    $foreign = SavedView::factory()->for($stranger)->for($stranger->currentWorkspace)->create(['name' => 'Elsewhere', 'is_shared' => true]);

    livewire(ListCompanies::class)
        ->call('applySavedView', $foreign->getKey())
        ->assertNotified('That view is no longer available.')
        ->assertSet('activeSavedViewId', null)
        ->assertActionHidden('renameSavedView', ['view' => $foreign->getKey()])
        ->assertActionHidden('shareSavedView', ['view' => $foreign->getKey()])
        ->assertActionHidden('deleteSavedView', ['view' => $foreign->getKey()])
        ->call('toggleDefaultSavedView', $foreign->getKey())
        ->assertNotified('That view is no longer available.')
        ->assertSet('defaultSavedViewId', null);

    expect($foreign->refresh()->name)->toBe('Elsewhere')
        ->and(preferenceRow()?->default_view_id)->toBeNull();
});

it('lets any member, a viewer too, save and share a view', function (): void {
    $viewer = savedViewMember(WorkspaceRole::Viewer);
    $this->actingAs($viewer);

    $component = livewire(ListCompanies::class)->callAction('saveSavedViewAs', ['name' => 'Viewer view']);
    $component->callAction('shareSavedView', arguments: ['view' => $component->get('activeSavedViewId')]);

    expect(SavedView::query()->withoutGlobalScopes()->sole()->is_shared)->toBeTrue();
});

it('lets a member apply but not change someone else\'s shared view', function (): void {
    $view = viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true]);
    $this->actingAs(savedViewMember(WorkspaceRole::Member));

    livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->assertActionHidden('renameSavedView', ['view' => $view->getKey()])
        ->assertActionHidden('deleteSavedView', ['view' => $view->getKey()])
        ->callAction('saveSavedView')
        ->assertForbidden();
});

it('lets an admin rename and delete someone else\'s shared view, but not see a private one', function (): void {
    $admin = savedViewMember(WorkspaceRole::Admin);
    $owner = savedViewMember(WorkspaceRole::Member);
    $shared = viewFor($owner, ['name' => 'Team', 'is_shared' => true]);
    $private = viewFor($owner, ['name' => 'Private']);
    $this->actingAs($admin);

    livewire(ListCompanies::class)
        ->call('applySavedView', $shared->getKey())
        ->callAction('renameSavedView', ['name' => 'Team pipeline'], ['view' => $shared->getKey()])
        ->assertHasNoActionErrors();

    expect($shared->refresh()->name)->toBe('Team pipeline')
        ->and($admin->can('update', $private))->toBeFalse()
        ->and($admin->can('delete', $shared))->toBeTrue();

    livewire(ListCompanies::class)->call('applySavedView', $shared->getKey())->callAction('deleteSavedView', arguments: ['view' => $shared->getKey()]);

    expect(SavedView::query()->withoutGlobalScopes()->whereKey($shared->getKey())->exists())->toBeFalse();
});

it('asks an admin to confirm before taking someone else\'s view back to its owner', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['name' => 'Team', 'is_shared' => true]);
    $this->actingAs(savedViewMember(WorkspaceRole::Admin));

    $component = livewire(ListCompanies::class)
        ->mountAction('stopSharingSavedView', ['view' => $view->getKey()])
        ->assertActionMounted('stopSharingSavedView')
        ->assertMountedActionModalSee("It goes back to {$owner->name} as a private view");

    expect($view->refresh()->is_shared)->toBeTrue();

    $component->callMountedAction();

    expect($view->refresh()->is_shared)->toBeFalse();
});

it('lets an admin who stops sharing someone else\'s view keep working on the table', function (): void {
    $view = viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true, 'state' => hotLeads()]);
    $this->actingAs(savedViewMember(WorkspaceRole::Admin));

    $component = livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->callAction('stopSharingSavedView', arguments: ['view' => $view->getKey()])
        ->assertSet('activeSavedViewId', null)
        ->set('tableSort', 'name:asc')
        ->call('revertSavedView')
        ->assertOk()
        ->assertDontSeeText('(modified)');

    expect($component->get('tableSort'))->toBe('name:asc')
        ->and($view->refresh()->is_shared)->toBeFalse();
});

it('lets the owner keep managing a view after an admin edits it', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['name' => 'Team', 'is_shared' => true, 'state' => hotLeads()]);

    $this->actingAs(savedViewMember(WorkspaceRole::Admin));
    livewire(ListCompanies::class)
        ->callAction('renameSavedView', ['name' => 'Team pipeline'], ['view' => $view->getKey()])
        ->assertHasNoActionErrors();
    expect($view->refresh()->name)->toBe('Team pipeline');

    $this->actingAs($owner);
    $component = livewire(ListCompanies::class)
        ->callAction('renameSavedView', ['name' => 'Mine again'], ['view' => $view->getKey()])
        ->assertHasNoActionErrors()
        ->callAction('stopSharingSavedView', arguments: ['view' => $view->getKey()]);

    expect($view->refresh()->name)->toBe('Mine again')
        ->and($view->is_shared)->toBeFalse();

    $component->callAction('deleteSavedView', arguments: ['view' => $view->getKey()]);

    expect(SavedView::query()->withoutGlobalScopes()->whereKey($view->getKey())->exists())->toBeFalse();
});

it('keeps its own view active and default after the owner stops sharing it', function (): void {
    $view = viewFor($this->user, ['name' => 'Team', 'is_shared' => true, 'state' => hotLeads()]);

    $component = livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->call('toggleDefaultSavedView', $view->getKey())
        ->callAction('stopSharingSavedView', arguments: ['view' => $view->getKey()])
        ->assertSet('activeSavedViewId', $view->getKey())
        ->assertSet('defaultSavedViewId', $view->getKey());

    expect($view->refresh()->is_shared)->toBeFalse()
        ->and(preferenceRow()->active_view_id)->toBe($view->getKey())
        ->and(preferenceRow()->default_view_id)->toBe($view->getKey())
        ->and($component->instance()->isSavedViewModified())->toBeFalse();
});

it('accepts the rename modal when the name is unchanged or only its case changed', function (): void {
    $view = viewFor($this->user, ['name' => 'Renewals']);

    livewire(ListCompanies::class)
        ->callAction('renameSavedView', ['name' => 'Renewals'], ['view' => $view->getKey()])
        ->assertHasNoActionErrors()
        ->callAction('renameSavedView', ['name' => 'RENEWALS'], ['view' => $view->getKey()])
        ->assertHasNoActionErrors();

    expect($view->refresh()->name)->toBe('RENEWALS');
});

it('lets the workspace owner rename a member\'s shared view', function (): void {
    $view = viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true]);

    livewire(ListCompanies::class)
        ->callAction('renameSavedView', ['name' => 'Team pipeline'], ['view' => $view->getKey()])
        ->assertHasNoActionErrors();

    expect($view->refresh()->name)->toBe('Team pipeline');
});

it('refuses an update from a former member who still owns a shared view', function (): void {
    $former = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($former, ['name' => 'Team', 'is_shared' => true]);
    $this->workspace->users()->detach($former);

    expect(fn () => resolve(UpdateSavedView::class)->execute($former, $view, ['name' => 'Mine']))
        ->toThrow(HttpException::class);

    expect($view->refresh()->name)->toBe('Team');
});

it('limits who can update or see a shared view', function (): void {
    $view = viewFor($this->user, ['is_shared' => true]);

    expect(savedViewMember(WorkspaceRole::Viewer)->can('update', $view))->toBeFalse()
        ->and(User::factory()->create()->can('view', $view))->toBeFalse();
});

it('offers save as new beside a modified view, even one the member cannot save', function (): void {
    $view = viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true, 'state' => hotLeads()]);
    $this->actingAs(savedViewMember(WorkspaceRole::Member));

    $component = livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->assertDontSeeHtml('fi-ta-views-save-as')
        ->set('tableSort', 'name:asc')
        ->assertSeeHtml('fi-ta-views-save-as')
        ->assertDontSeeHtml("mountAction('saveSavedView')")
        ->callAction('saveSavedViewAs', ['name' => 'My team copy']);

    expect($component->get('activeSavedViewId'))->not->toBe($view->getKey())
        ->and($component->instance()->isSavedViewModified())->toBeFalse()
        ->and($view->refresh()->state->sort)->toBe('created_at:desc');
});

it('offers a search box only once a member has seven views to choose from', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->count(6)->sequence(fn ($sequence): array => ['name' => "View {$sequence->index}"])->create();

    livewire(ListCompanies::class)->assertDontSeeHtml('placeholder="Search views"');

    viewFor($this->user, ['name' => 'Seventh']);

    livewire(ListCompanies::class)->assertSeeHtml('placeholder="Search views"');
});

it('names the owner when an admin is about to delete someone else\'s view', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $theirs = viewFor($owner, ['name' => 'Team', 'is_shared' => true]);
    $admin = savedViewMember(WorkspaceRole::Admin);
    $mine = viewFor($admin, ['name' => 'Mine']);
    $this->actingAs($admin);

    livewire(ListCompanies::class)
        ->mountAction('deleteSavedView', ['view' => $theirs->getKey()])
        ->assertMountedActionModalSee("It belongs to {$owner->name}, who will be told you deleted it.");

    livewire(ListCompanies::class)
        ->mountAction('deleteSavedView', ['view' => $mine->getKey()])
        ->assertMountedActionModalSee('Members who use this view keep what their table shows.')
        ->assertMountedActionModalDontSee('It belongs to');
});

it('confirms a deleted view', function (): void {
    $view = viewFor($this->user, ['name' => 'Renewals']);

    livewire(ListCompanies::class)
        ->callAction('deleteSavedView', arguments: ['view' => $view->getKey()])
        ->assertNotified('"Renewals" deleted');
});

it('escapes the view name in the toasts it sends', function (): void {
    $name = '<a href="https://evil.example">Renewals</a>';
    $escaped = e($name);

    livewire(ListCompanies::class)
        ->callAction('saveSavedViewAs', ['name' => $name])
        ->assertNotified("\"{$escaped}\" saved as a new view");

    $view = SavedView::query()->withoutGlobalScopes()->sole();

    livewire(ListCompanies::class)
        ->callAction('shareSavedView', arguments: ['view' => $view->getKey()])
        ->assertNotified("\"{$escaped}\" is shared with the workspace")
        ->callAction('deleteSavedView', arguments: ['view' => $view->getKey()])
        ->assertNotified("\"{$escaped}\" deleted");
});

it('tells the owner when an admin deletes their view', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['name' => 'Renewals', 'is_shared' => true]);
    $admin = savedViewMember(WorkspaceRole::Admin);
    $this->actingAs($admin);

    livewire(ListCompanies::class)->callAction('deleteSavedView', arguments: ['view' => $view->getKey()]);

    $notice = $owner->notifications()->sole();

    expect(SavedView::query()->withoutGlobalScopes()->whereKey($view->getKey())->exists())->toBeFalse()
        ->and($notice->type)->toBe(SavedViewChangedNotification::class)
        ->and($notice->actor_id)->toBe($admin->getKey())
        ->and($notice->subject_id)->toBe($this->workspace->getKey())
        ->and($notice->data)->toBe(['view_name' => 'Renewals', 'entity' => CrmEntity::Company->value, 'change' => SavedViewChange::Deleted->value]);
});

it('tells no one about a rename that loses the race to a delete', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['name' => 'Renewals', 'is_shared' => true]);
    $admin = savedViewMember(WorkspaceRole::Admin);
    $staleCopy = SavedView::query()->withoutGlobalScopes()->findOrFail($view->getKey());
    resolve(DeleteSavedView::class)->execute($admin, $view);
    $owner->notifications()->delete();

    expect(fn (): SavedView => resolve(UpdateSavedView::class)->execute($admin, $staleCopy, ['name' => 'Renamed']))
        ->toThrow(ModelNotFoundException::class)
        ->and($owner->notifications()->count())->toBe(0);
});

it('shows the admin the view is gone when it is deleted while they rename it', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['name' => 'Renewals', 'is_shared' => true]);
    $this->actingAs(savedViewMember(WorkspaceRole::Admin));

    $component = livewire(ListCompanies::class)->mountAction('renameSavedView', ['view' => $view->getKey()]);

    DB::listen(function (QueryExecuted $query) use ($view): void {
        if (str_contains($query->sql, 'lower(name) = lower(?)')) {
            DB::table('saved_views')->where('id', $view->getKey())->delete();
        }
    });

    $component->fillForm(['name' => 'Renamed'])
        ->callMountedAction()
        ->assertNotified('That view is no longer available.');

    expect($owner->notifications()->count())->toBe(0);
});

it('tells the owner once when two admins delete their view at the same moment', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['name' => 'Renewals', 'is_shared' => true]);
    $admin = savedViewMember(WorkspaceRole::Admin);
    $sameViewInAnotherRequest = SavedView::query()->withoutGlobalScopes()->findOrFail($view->getKey());

    $first = resolve(DeleteSavedView::class)->execute($admin, $view);
    $second = resolve(DeleteSavedView::class)->execute($admin, $sameViewInAnotherRequest);

    expect($first)->toBeTrue()
        ->and($second)->toBeFalse()
        ->and($owner->notifications()->count())->toBe(1);
});

it('tells the owner when an admin stops sharing their view', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['name' => 'Renewals', 'is_shared' => true]);
    $this->actingAs(savedViewMember(WorkspaceRole::Admin));

    livewire(ListCompanies::class)->callAction('stopSharingSavedView', arguments: ['view' => $view->getKey()]);

    expect($owner->notifications()->sole()->data)->toBe(['view_name' => 'Renewals', 'entity' => CrmEntity::Company->value, 'change' => SavedViewChange::Unshared->value]);
});

it('tells no one when a member deletes or stops sharing their own view', function (): void {
    $kept = viewFor($this->user, ['name' => 'Kept', 'is_shared' => true]);
    $deleted = viewFor($this->user, ['name' => 'Deleted', 'is_shared' => true]);

    livewire(ListCompanies::class)
        ->mountAction('stopSharingSavedView', ['view' => $kept->getKey()])
        ->callAction('deleteSavedView', arguments: ['view' => $deleted->getKey()]);

    expect($kept->refresh()->is_shared)->toBeFalse()
        ->and(Notification::query()->count())->toBe(0);
});

it('tells the owner when an admin renames their view', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['name' => 'Team', 'is_shared' => true]);
    viewFor($owner, ['name' => 'Other', 'is_shared' => true]);
    $admin = savedViewMember(WorkspaceRole::Admin);
    $this->actingAs($admin);

    livewire(ListCompanies::class)
        ->mountAction('renameSavedView', ['view' => $view->getKey()])
        ->assertMountedActionModalSee("It belongs to {$owner->name}, who will be told you renamed it.")
        ->setActionData(['name' => 'Team pipeline'])
        ->callMountedAction();

    $notice = $owner->notifications()->sole();

    expect($view->refresh()->name)->toBe('Team pipeline')
        ->and($notice->actor_id)->toBe($admin->getKey())
        ->and($notice->data)->toBe(['view_name' => 'Team pipeline', 'entity' => CrmEntity::Company->value, 'change' => SavedViewChange::Renamed->value, 'previous_name' => 'Team']);
});

it('asks an admin before saving over someone else\'s view, then tells the owner', function (): void {
    $owner = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($owner, ['name' => 'Team', 'is_shared' => true, 'state' => hotLeads()]);
    viewFor($owner, ['name' => 'Other', 'is_shared' => true]);
    $this->actingAs(savedViewMember(WorkspaceRole::Admin));

    livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->set('tableSort', 'name:asc')
        ->mountAction('saveSavedView')
        ->assertMountedActionModalSee('Save over "Team"?')
        ->assertMountedActionModalSee("It belongs to {$owner->name}, who will be told you changed it.")
        ->callMountedAction()
        ->assertNotified('"Team" saved');

    expect($view->refresh()->state->sort)->toBe('name:asc')
        ->and($owner->notifications()->sole()->data)->toBe(['view_name' => 'Team', 'entity' => CrmEntity::Company->value, 'change' => SavedViewChange::Updated->value]);
});

it('saves over your own view without asking and tells no one', function (): void {
    $view = viewFor($this->user, ['name' => 'Mine', 'is_shared' => true, 'state' => hotLeads()]);

    livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->set('tableSort', 'name:asc')
        ->mountAction('saveSavedView')
        ->assertActionNotMounted()
        ->callAction('renameSavedView', ['name' => 'Still mine'], ['view' => $view->getKey()]);

    expect($view->refresh()->state->sort)->toBe('name:asc')
        ->and($view->name)->toBe('Still mine')
        ->and(Notification::query()->count())->toBe(0);
});

it('tells the owner once when a second save changes nothing', function (): void {
    $view = viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true, 'state' => hotLeads()]);
    $this->actingAs(savedViewMember(WorkspaceRole::Admin));

    livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->set('tableSort', 'name:asc')
        ->callAction('saveSavedView')
        ->callAction('saveSavedView');

    expect(Notification::query()->count())->toBe(1);
});

it('tells the member instead of failing when the view they revert was deleted elsewhere', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->set('tableSort', 'name:asc');

    $view->delete();

    $component->call('revertSavedView')
        ->assertNotified('That view is no longer available.')
        ->assertSet('activeSavedViewId', null)
        ->assertSet('appliedSavedViewHash', null);

    expect($component->get('tableSort'))->toBe('name:asc');
});

it('tells the member instead of failing when the view they save over was deleted elsewhere', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->set('tableSort', 'name:asc');

    $view->delete();

    $component->callAction('saveSavedView')
        ->assertNotified('That view is no longer available.')
        ->assertSet('activeSavedViewId', null);

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('tells the member instead of failing when the view they set as default was deleted elsewhere', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());

    $view->delete();

    $component->call('toggleDefaultSavedView', $view->getKey())
        ->assertNotified('That view is no longer available.')
        ->assertSet('activeSavedViewId', null)
        ->assertSet('defaultSavedViewId', null);

    expect(preferenceRow()?->default_view_id)->toBeNull();
});

it('closes the modal and tells the member when the view it acts on was deleted elsewhere', function (string $action): void {
    $view = viewFor($this->user, ['name' => 'Renewals']);
    $component = livewire(ListCompanies::class)->mountAction($action, ['view' => $view->getKey()]);

    $view->delete();

    $component->callMountedAction()
        ->assertNotified('That view is no longer available.')
        ->assertActionNotMounted($action);
})->with(['renameSavedView', 'shareSavedView', 'deleteSavedView']);

it('closes the stop sharing modal and tells the admin when the view was deleted elsewhere', function (): void {
    $view = viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Renewals', 'is_shared' => true]);
    $this->actingAs(savedViewMember(WorkspaceRole::Admin));
    $component = livewire(ListCompanies::class)->mountAction('stopSharingSavedView', ['view' => $view->getKey()]);

    $view->delete();

    $component->callMountedAction()
        ->assertNotified('That view is no longer available.')
        ->assertActionNotMounted('stopSharingSavedView');
});

it('shows the name error when a rename races another save to the same name', function (): void {
    $view = viewFor($this->user, ['name' => 'Old pipeline']);

    SavedView::updating(function (): void {
        SavedView::withoutEvents(function (): void {
            viewFor(test()->user, ['name' => 'Hot leads']);
        });
    });

    livewire(ListCompanies::class)
        ->callAction('renameSavedView', ['name' => 'Hot leads'], ['view' => $view->getKey()])
        ->assertHasActionErrors(['name']);

    expect($view->refresh()->name)->toBe('Old pipeline');
});
