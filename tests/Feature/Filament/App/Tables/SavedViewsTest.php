<?php

declare(strict_types=1);

use App\Actions\Views\CreateSavedView;
use App\Actions\Views\DeleteSavedView;
use App\Actions\Views\DuplicateSavedView;
use App\Actions\Views\EnsureSavedViewNameIsFree;
use App\Actions\Views\ReorderViews;
use App\Actions\Views\SaveBoardState;
use App\Actions\Views\UpdateSavedView;
use App\Data\BoardStateData;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\SavedViewType;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\HasSavedViews;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\User;
use App\Policies\SavedViewPolicy;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(
    HasSavedViews::class,
    PersistsTableState::class,
    SavedViewPolicy::class,
    CreateSavedView::class,
    UpdateSavedView::class,
    DeleteSavedView::class,
    DuplicateSavedView::class,
    ReorderViews::class,
    EnsureSavedViewNameIsFree::class,
    SaveBoardState::class,
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
        perPage: 50,
    );
}

function preferenceRow(): ?TablePreference
{
    return TablePreference::query()->withoutGlobalScopes()->first();
}

it('applies columns, sort, filters and page size in one call', function (string $page, CrmEntity $entity): void {
    $view = viewFor($this->user, ['entity' => $entity, 'state' => hotLeads()]);
    $updatedAt = $view->updated_at;
    $this->travelTo(now()->addHour());

    $component = Livewire::withQueryParams(['view' => SavedView::BUILT_IN_LIST_LINK])->test($page)->call('applySavedView', $view->getKey());

    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and((int) $component->get('tableRecordsPerPage'))->toBe(50)
        ->and($component->get('tableFilters.trashed.value'))->toEqual(0)
        ->and($component->instance()->getTableFiltersForm()->getRawState()['trashed']['value'])->toEqual(0)
        ->and($component->get('tableColumns.0.name'))->toBe('created_at')
        ->and($component->get('tableColumns.0.isToggled'))->toBeFalse()
        ->and($view->refresh()->updated_at)->toEqual($updatedAt)
        ->and(preferenceRow()->active_view_id)->toBe($view->getKey());
})->with('view list pages');

function sortedKeys(array $value): array
{
    if (! array_is_list($value)) {
        ksort($value);
    }

    return array_map(fn (mixed $item): mixed => is_array($item) ? sortedKeys($item) : $item, $value);
}

it('writes nothing to a view whose stored hash predates search leaving the state', function (): void {
    $state = new TableStateData(columns: [['type' => 'column', 'name' => 'created_at', 'isToggled' => true]], sort: 'name:asc');
    $view = viewFor($this->user, ['state' => $state]);
    TablePreference::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'state' => $state,
        'active_view_id' => $view->getKey(),
        'view_order' => null,
        'applied_view_hash' => hash('xxh128', json_encode(sortedKeys([...$state->toArray(), 'search' => null, 'columnSearches' => []]), JSON_THROW_ON_ERROR)),
    ]);
    $updatedAt = $view->updated_at;
    $this->travelTo(now()->addHour());

    livewire(ListCompanies::class)->call('$refresh');

    expect($view->refresh()->updated_at)->toEqual($updatedAt);
});

it('opens a view stored with a search without writing it and applies no search', function (string $page, CrmEntity $entity): void {
    $view = viewFor($this->user, ['entity' => $entity, 'state' => hotLeads()]);
    DB::table('saved_views')->where('id', $view->getKey())->update(['state' => json_encode([...$view->state->toArray(), 'search' => 'acme', 'columnSearches' => ['name' => 'acme']])]);
    $updatedAt = $view->refresh()->updated_at;
    $this->travelTo(now()->addHour());

    $component = livewire($page)->call('applySavedView', $view->getKey())->call('$refresh');

    expect($component->get('tableSearch'))->toBeEmpty()
        ->and($component->get('tableColumnSearches'))->toBeEmpty()
        ->and($view->refresh()->updated_at)->toEqual($updatedAt);
})->with('view list pages');

it('falls back to the default page size when a view holds one the table does not offer', function (): void {
    $view = viewFor($this->user, ['state' => new TableStateData(perPage: 200)]);

    expect((int) livewire(ListCompanies::class)->set('tableRecordsPerPage', 50)->call('applySavedView', $view->getKey())->get('tableRecordsPerPage'))->toBe(25);
});

it('applies a sort through the grouping a view names', function (): void {
    $view = viewFor($this->user, ['state' => new TableStateData(sort: 'name:asc')]);

    $component = livewire(ListCompanies::class)->call('sortTable', 'created_at', 'desc')->call('applySavedView', $view->getKey());

    expect($component->get('tableSort'))->toBe('name:asc');
});

it('saves the current table as a new view without widths and applies it', function (): void {
    $component = livewire(ListCompanies::class)
        ->call('sortTable', 'created_at', 'desc')
        ->call('updateResizableColumnWidth', 'created_at', '300')
        ->callAction('saveSavedViewAs', ['name' => 'Hot leads'])
        ->assertHasNoActionErrors()
        ->assertNotified('"Hot leads" saved as a new view');

    $view = SavedView::query()->withoutGlobalScopes()->sole();

    expect($view->name)->toBe('Hot leads')
        ->and($view->type)->toBe(SavedViewType::Table)
        ->and($view->state->sort)->toBe('created_at:desc')
        ->and($view->state->widths)->toBe([])
        ->and($component->get('activeSavedViewId'))->toBe($view->getKey());

    $component->assertSeeText('Hot leads')->assertDontSeeText('Discard changes');
});

it('refuses a view name the member already uses, ignoring case', function (): void {
    viewFor($this->user, ['name' => 'Hot leads']);

    livewire(ListCompanies::class)
        ->callAction('saveSavedViewAs', ['name' => 'hot LEADS '])
        ->assertHasActionErrors(['name']);

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('lets two members use the same view name', function (): void {
    viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Hot leads']);

    livewire(ListCompanies::class)->callAction('saveSavedViewAs', ['name' => 'Hot leads'])->assertHasNoActionErrors();

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('ends on the original state when the page size is changed and set back from the select', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())
        ->set('tableRecordsPerPage', '100')
        ->set('tableRecordsPerPage', '50');

    $preference = preferenceRow();

    expect($view->refresh()->state->perPage)->toBe(50)
        ->and($view->state->sort)->toBe('created_at:desc')
        ->and($preference->state->withoutWidths()->hash())->toBe($preference->applied_view_hash);
});

it('writes nothing to a view when a column width changes', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $updatedAt = $view->updated_at;
    $this->travelTo(now()->addHour());

    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('updateResizableColumnWidth', 'created_at', '300');

    expect($view->refresh()->updated_at)->toEqual($updatedAt)
        ->and($view->state->widths)->toBe([]);
});

it('stores a filter change on the active view with no save step and keeps it on a fresh mount', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);

    livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->call('setFilterRules', [['field' => 'name', 'condition' => 'contains', 'value' => 'acme']]);

    $rules = data_get($view->refresh()->state->filters, 'conditions.rules');

    expect($rules)->toHaveCount(1)
        ->and($rules[0])->toMatchArray(['field' => 'name', 'condition' => 'contains', 'value' => 'acme']);

    $fresh = Livewire::withQueryParams(['view' => $view->getKey()])->test(ListCompanies::class);

    expect($fresh->get('tableFilters.conditions.rules.0.value'))->toBe('acme')
        ->and($fresh->get('activeSavedViewId'))->toBe($view->getKey());
});

it('stores a sort change on the active view with no save step and keeps it on a fresh mount', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);

    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('sortTable', 'name', 'asc');

    expect($view->refresh()->state->sort)->toBe('name:asc');

    $fresh = Livewire::withQueryParams(['view' => $view->getKey()])->test(ListCompanies::class);

    expect($fresh->get('tableSort'))->toBe('name:asc');
});

it('stores a column change on the active view with no save step', function (): void {
    $view = viewFor($this->user, ['state' => new TableStateData(sort: 'name:desc')]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());

    $component->call('hideTableColumn', 'created_at');

    $stored = collect($view->refresh()->state->columns)->firstWhere('name', 'created_at');

    expect($stored['isToggled'])->toBeFalse();
});

it('stores a column label on the active view with no save step', function (): void {
    $view = viewFor($this->user, ['state' => new TableStateData(sort: 'name:desc')]);

    livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->callAction('editTableColumnLabel', data: ['label' => 'Joined'], arguments: ['column' => 'created_at']);

    expect($view->refresh()->state->labels)->toBe(['created_at' => 'Joined']);
});

it('writes a view once per change and nothing for a request that changes nothing', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('sortTable', 'name', 'asc');
    $updatedAt = $view->refresh()->updated_at;

    $this->travelTo(now()->addHour());
    $component->call('$refresh')->call('sortTable', 'name', 'asc')->call('updateResizableColumnWidth', 'created_at', '300');

    expect($view->refresh()->updated_at)->toEqual($updatedAt)
        ->and($view->state->widths)->toBe([]);
});

it('writes nothing to a view when it is applied', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $updatedAt = $view->updated_at;

    $this->travelTo(now()->addHour());
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey());

    expect($view->refresh()->updated_at)->toEqual($updatedAt);
});

it('writes nothing to a view when it opens from a link', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $updatedAt = $view->updated_at;

    $this->travelTo(now()->addHour());
    $component = Livewire::withQueryParams(['view' => $view->getKey()])->test(ListCompanies::class);
    $component->call('$refresh');

    expect($component->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($view->refresh()->updated_at)->toEqual($updatedAt);
});

it('writes no view for a change on the built-in list', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $updatedAt = $view->updated_at;

    $this->travelTo(now()->addHour());
    livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('setFilterRules', [['field' => 'name', 'condition' => 'contains', 'value' => 'acme']]);

    expect($view->refresh()->updated_at)->toEqual($updatedAt)
        ->and($view->state->sort)->toBe('created_at:desc')
        ->and(preferenceRow()->state->sort)->toBe('name:asc');
});

it('writes neither view when the member switches from one to the other', function (): void {
    $first = viewFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $second = viewFor($this->user, ['name' => 'By name', 'state' => new TableStateData(sort: 'name:asc')]);
    $before = [$first->updated_at, $second->updated_at];
    $this->travelTo(now()->addHour());

    livewire(ListCompanies::class)->call('applySavedView', $first->getKey())->call('applySavedView', $second->getKey());

    expect([$first->refresh()->updated_at, $second->refresh()->updated_at])->toEqual($before)
        ->and($first->state->sort)->toBe('created_at:desc')
        ->and($second->state->sort)->toBe('name:asc');
});

it('writes no view when the member returns to the built-in list', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $updatedAt = $view->updated_at;
    $this->travelTo(now()->addHour());

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('applyOwnTable');

    expect($component->get('activeSavedViewId'))->toBeNull()
        ->and($view->refresh()->updated_at)->toEqual($updatedAt)
        ->and($view->state->sort)->toBe('created_at:desc');
});

it('leaves no deleted view active and writes nothing to another view', function (): void {
    $active = viewFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $other = viewFor($this->user, ['name' => 'By name', 'state' => new TableStateData(sort: 'name:asc')]);
    $updatedAt = $other->updated_at;
    $this->travelTo(now()->addHour());

    livewire(ListCompanies::class)
        ->call('applySavedView', $active->getKey())
        ->callAction('deleteSavedView', arguments: ['view' => $active->getKey()])
        ->assertSet('activeSavedViewId', null)
        ->call('sortTable', 'created_at', 'asc')
        ->assertNotNotified(__('filament/panel.tables.views.unavailable'));

    expect(SavedView::query()->withoutGlobalScopes()->pluck('name')->all())->toBe(['By name'])
        ->and($other->refresh()->updated_at)->toEqual($updatedAt)
        ->and($other->state->sort)->toBe('name:asc');
});

it('renders no discard or save control on a changed view', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);

    livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->call('sortTable', 'name', 'asc')
        ->assertDontSeeText(['Discard changes', 'Save as new view', 'More ways to save']);
});

it('stores the view order when another request writes the member\'s first preference row at the same moment', function (): void {
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

    $component->call('reorderViews', [$view->getKey()])->assertOk();

    expect(TablePreference::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(preferenceRow()->view_order)->toBe([$view->getKey()]);
});

it('brings back the last state within a session, and the view as it was left in the next one', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('reorderViews', [$view->getKey()])->call('sortTable', 'name', 'asc');

    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('tableSort'))->toBe('name:asc')
        ->and($revisit->get('activeSavedViewId'))->toBe($view->getKey());

    session()->flush();
    $nextSession = livewire(ListCompanies::class);

    expect($nextSession->get('tableSort'))->toBe('name:asc')
        ->and($nextSession->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($view->refresh()->state->sort)->toBe('name:asc');
});

it('opens a new session on the default view over another applied one, and keeps it for that session', function (): void {
    $default = viewFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $other = viewFor($this->user, ['name' => 'By name', 'state' => new TableStateData(sort: 'name:asc')]);
    livewire(ListCompanies::class)->call('reorderViews', [$default->getKey()])->call('applySavedView', $other->getKey());

    $updatedAt = [$default->updated_at, $other->updated_at];
    $this->travelTo(now()->addHour());
    session()->flush();

    expect(livewire(ListCompanies::class)->get('activeSavedViewId'))->toBe($default->getKey());

    $sameSession = livewire(ListCompanies::class);

    expect($sameSession->get('activeSavedViewId'))->toBe($default->getKey())
        ->and($sameSession->get('tableSort'))->toBe('created_at:desc')
        ->and([$default->refresh()->updated_at, $other->refresh()->updated_at])->toEqual($updatedAt);
});

it('keeps the last state across sessions for a member with no default view', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('sortTable', 'name', 'asc');

    session()->flush();

    expect(livewire(ListCompanies::class)->get('tableSort'))->toBe('name:asc');
});

it('opens on the default view when the member has no saved state, and writes nothing', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    TablePreference::factory()->for($this->user)->for($this->workspace)->create(['view_order' => [$view->getKey()]]);
    $updatedAt = preferenceRow()->updated_at;
    $viewUpdatedAt = $view->updated_at;

    $this->travelTo(now()->addHour());
    $component = livewire(ListCompanies::class);

    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($view->refresh()->updated_at)->toEqual($viewUpdatedAt)
        ->and(preferenceRow()->updated_at)->toEqual($updatedAt);
});

it('offers the standard view and nothing to manage when no view is saved', function (): void {
    livewire(ListCompanies::class)
        ->assertSeeText('All companies')
        ->assertActionHidden('renameSavedView')
        ->assertActionHidden('deleteSavedView');
});

it('keeps the table and clears the pointers when a view is deleted', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('reorderViews', [$view->getKey()])
        ->callAction('deleteSavedView', arguments: ['view' => $view->getKey()])
        ->assertSet('activeSavedViewId', null)
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
        ->call('reorderViews', [$other->getKey()])
        ->callAction('deleteSavedView', arguments: ['view' => $other->getKey()])
        ->assertSet('activeSavedViewId', $active->getKey())
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

it('lists the built-in table and the saved views in the member\'s order', function (): void {
    $alpha = viewFor($this->user, ['name' => 'Alpha']);
    viewFor($this->user, ['name' => 'Beta']);

    livewire(ListCompanies::class)
        ->assertSeeHtmlInOrder(['All companies', 'Alpha', 'Beta'])
        ->call('reorderViews', [$alpha->getKey(), 'list'])
        ->assertSeeHtmlInOrder(['Alpha', 'All companies', 'Beta']);
});

it('keeps views apart per table and per workspace', function (): void {
    viewFor($this->user, ['name' => 'People view', 'entity' => CrmEntity::People]);
    SavedView::factory()->for($this->user)->for(User::factory()->withWorkspace()->create()->currentWorkspace)->create(['name' => 'Elsewhere']);

    livewire(ListCompanies::class)->assertDontSeeText(['People view', 'Elsewhere']);
});

it('never offers, applies, duplicates or edits another member\'s view', function (): void {
    $other = savedViewMember(WorkspaceRole::Member);
    $theirs = viewFor($other, ['name' => 'Renewals this month']);

    livewire(ListCompanies::class)
        ->assertDontSeeText('Renewals this month')
        ->call('applySavedView', $theirs->getKey())
        ->assertNotified('That view is no longer available.')
        ->assertSet('activeSavedViewId', null)
        ->call('duplicateSavedView', $theirs->getKey())
        ->assertNotified('That view is no longer available.');

    expect($this->user->can('view', $theirs))->toBeFalse()
        ->and($this->user->can('update', $theirs))->toBeFalse()
        ->and(savedViewMember(WorkspaceRole::Admin)->can('delete', $theirs))->toBeFalse();
});

it('refuses a view id from another workspace at every entry point', function (): void {
    $stranger = User::factory()->withWorkspace()->create();
    $foreign = SavedView::factory()->for($stranger)->for($stranger->currentWorkspace)->create(['name' => 'Elsewhere']);

    livewire(ListCompanies::class)
        ->call('applySavedView', $foreign->getKey())
        ->assertNotified('That view is no longer available.')
        ->assertSet('activeSavedViewId', null)
        ->assertActionHidden('renameSavedView', ['view' => $foreign->getKey()])
        ->assertActionHidden('deleteSavedView', ['view' => $foreign->getKey()])
        ->call('reorderViews', [$foreign->getKey()])
        ->assertOk();

    expect($foreign->refresh()->name)->toBe('Elsewhere')
        ->and(preferenceRow()?->view_order)->toBeNull();
});

it('lets any member, a viewer too, save a view', function (): void {
    $this->actingAs(savedViewMember(WorkspaceRole::Viewer));

    livewire(ListCompanies::class)->callAction('saveSavedViewAs', ['name' => 'Viewer view'])->assertHasNoActionErrors();

    expect(SavedView::query()->withoutGlobalScopes()->sole()->name)->toBe('Viewer view');
});

it('refuses to update another member\'s view, whatever reaches the action', function (): void {
    $view = viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Team', 'state' => hotLeads()]);
    $member = savedViewMember(WorkspaceRole::Member);

    expect(fn () => resolve(UpdateSavedView::class)->execute($member, $view, ['state' => new TableStateData(sort: 'name:asc')]))
        ->toThrow(HttpException::class);

    expect($view->refresh()->state->sort)->toBe('created_at:desc');
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

it('refuses an update from a former member who still owns a view', function (): void {
    $former = savedViewMember(WorkspaceRole::Member);
    $view = viewFor($former, ['name' => 'Team']);
    $this->workspace->users()->detach($former);

    expect(fn () => resolve(UpdateSavedView::class)->execute($former, $view, ['name' => 'Mine']))
        ->toThrow(HttpException::class);

    expect($view->refresh()->name)->toBe('Team');
});

it('always offers a search box', function (): void {
    livewire(ListCompanies::class)->assertSeeHtml('placeholder="Search views…"');
});

it('asks for confirmation before deleting a view', function (): void {
    $view = viewFor($this->user, ['name' => 'Mine']);

    livewire(ListCompanies::class)
        ->mountAction('deleteSavedView', ['view' => $view->getKey()])
        ->assertMountedActionModalSee('The view is removed. Your table keeps what it shows now.');
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
        ->callAction('deleteSavedView', arguments: ['view' => $view->getKey()])
        ->assertNotified("\"{$escaped}\" deleted");
});

it('refuses a rename that loses the race to a delete', function (): void {
    $view = viewFor($this->user, ['name' => 'Renewals']);
    $staleCopy = SavedView::query()->withoutGlobalScopes()->findOrFail($view->getKey());
    resolve(DeleteSavedView::class)->execute($this->user, $view);

    expect(fn (): SavedView => resolve(UpdateSavedView::class)->execute($this->user, $staleCopy, ['name' => 'Renamed']))
        ->toThrow(ModelNotFoundException::class);
});

it('shows the member the view is gone when it is deleted while they rename it', function (): void {
    $view = viewFor($this->user, ['name' => 'Renewals']);

    $component = livewire(ListCompanies::class)->mountAction('renameSavedView', ['view' => $view->getKey()]);

    DB::listen(function (QueryExecuted $query) use ($view): void {
        if (str_contains($query->sql, 'lower(name) = lower(?)')) {
            DB::table('saved_views')->where('id', $view->getKey())->delete();
        }
    });

    $component->fillForm(['name' => 'Renamed'])
        ->callMountedAction()
        ->assertNotified('That view is no longer available.');
});

it('reports one delete when two requests delete the same view at the same moment', function (): void {
    $view = viewFor($this->user, ['name' => 'Renewals']);
    $sameViewInAnotherRequest = SavedView::query()->withoutGlobalScopes()->findOrFail($view->getKey());

    $first = resolve(DeleteSavedView::class)->execute($this->user, $view);
    $second = resolve(DeleteSavedView::class)->execute($this->user, $sameViewInAnotherRequest);

    expect($first)->toBeTrue()->and($second)->toBeFalse();
});

it('tells the member instead of failing when the view they change was deleted elsewhere', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());

    $view->delete();

    $component->call('sortTable', 'name', 'asc')
        ->assertNotified('That view is no longer available.')
        ->assertSet('activeSavedViewId', null)
        ->assertSet('appliedSavedViewHash', null);

    expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($component->get('tableSort'))->toBe('name:asc');
});

it('closes the modal and tells the member when the view it acts on was deleted elsewhere', function (string $action): void {
    $view = viewFor($this->user, ['name' => 'Renewals']);
    $component = livewire(ListCompanies::class)->mountAction($action, ['view' => $view->getKey()]);

    $view->delete();

    $component->callMountedAction()
        ->assertNotified('That view is no longer available.')
        ->assertActionNotMounted($action);
})->with(['renameSavedView', 'deleteSavedView']);

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

it('duplicates a view as a private copy the member owns', function (): void {
    $view = viewFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);

    livewire(ListCompanies::class)->call('duplicateSavedView', $view->getKey());

    $copy = SavedView::query()->withoutGlobalScopes()->where('name', 'Hot leads copy')->sole();
    expect($copy->user_id)->toBe($this->user->getKey())
        ->and($copy->state->sort)->toBe('created_at:desc')
        ->and($copy->entity)->toBe(CrmEntity::Company);
});

it('numbers a copy when the member already has one', function (): void {
    $view = viewFor($this->user, ['name' => 'Hot leads']);
    viewFor($this->user, ['name' => 'hot leads COPY']);

    livewire(ListCompanies::class)->call('duplicateSavedView', $view->getKey());

    expect(SavedView::query()->withoutGlobalScopes()->where('name', 'Hot leads copy 2')->exists())->toBeTrue();
});

it('never duplicates another member\'s view', function (): void {
    $private = viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Mine only']);

    livewire(ListCompanies::class)->call('duplicateSavedView', $private->getKey())->assertNotified('That view is no longer available.');
    expect(SavedView::query()->withoutGlobalScopes()->where('name', 'Mine only copy')->exists())->toBeFalse();
});

it('duplicates a view whose name is already at the length limit', function (): void {
    $view = viewFor($this->user, ['name' => str_repeat('a', 255)]);

    livewire(ListCompanies::class)->call('duplicateSavedView', $view->getKey())->assertOk();

    $copy = SavedView::query()->withoutGlobalScopes()->where('user_id', $this->user->getKey())->whereKeyNot($view->getKey())->sole();
    expect(mb_strlen($copy->name))->toBeLessThanOrEqual(255)
        ->and($copy->name)->toEndWith(' copy')
        ->and($copy->name)->toStartWith(str_repeat('a', 200));
});

it('numbers the second copy of a view at the length limit', function (): void {
    $view = viewFor($this->user, ['name' => str_repeat('a', 255)]);

    livewire(ListCompanies::class)
        ->call('duplicateSavedView', $view->getKey())
        ->call('duplicateSavedView', $view->getKey());

    $names = SavedView::query()->withoutGlobalScopes()->whereKeyNot($view->getKey())->pluck('name');
    expect($names)->toHaveCount(2)
        ->and($names->every(fn (string $name): bool => mb_strlen($name) <= 255))->toBeTrue()
        ->and($names->filter(fn (string $name): bool => str_ends_with($name, ' copy 2')))->toHaveCount(1);
});

it('tells the member when the copy name is taken between the check and the write', function (): void {
    $view = viewFor($this->user, ['name' => 'Hot leads']);
    SavedView::creating(function (SavedView $copy): void {
        if ($copy->name === 'Hot leads copy') {
            SavedView::withoutEvents(fn () => viewFor($this->user, ['name' => 'Hot leads copy']));
        }
    });

    livewire(ListCompanies::class)->call('duplicateSavedView', $view->getKey())->assertNotified('You already have a view with this name.');
});

it('brings back the member\'s own table after a saved view', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('sortTable', 'name', 'asc');

    $component->call('applySavedView', $view->getKey());
    expect($component->get('tableSort'))->toBe('created_at:desc');

    $component->call('applyOwnTable');
    expect($component->get('tableSort'))->toBe('name:asc')
        ->and($component->get('activeSavedViewId'))->toBeNull()
        ->and(preferenceRow()->active_view_id)->toBeNull()
        ->and(preferenceRow()->state->sort)->toBe('name:asc');
});

it('brings back the member\'s own table after changing a view created from it', function (): void {
    $component = livewire(ListCompanies::class)
        ->call('sortTable', 'name', 'asc')
        ->callAction('saveSavedViewAs', ['name' => 'Hot leads'])
        ->assertHasNoActionErrors();

    $component->call('sortTable', 'created_at', 'desc');
    $component->call('applyOwnTable');

    expect($component->get('tableSort'))->toBe('name:asc')
        ->and(SavedView::query()->withoutGlobalScopes()->sole()->state->sort)->toBe('created_at:desc');

    expect(livewire(ListCompanies::class)->get('tableSort'))->toBe('name:asc');
});

it('keeps the member\'s own table across sessions while a view is applied', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applySavedView', $view->getKey());

    session()->flush();

    expect(livewire(ListCompanies::class)->call('applyOwnTable')->get('tableSort'))->toBe('name:asc');
});

it('writes no view when the member changes their own table', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->call('applyOwnTable')
        ->call('sortTable', 'name', 'asc');

    expect($view->refresh()->state->sort)->toBe('created_at:desc')
        ->and($component->get('activeSavedViewId'))->toBeNull()
        ->and($component->get('appliedSavedViewHash'))->toBeNull()
        ->and(preferenceRow()->own_state)->toBeNull();
});

it('stores the member\'s own table without widths and keeps their widths when it comes back', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)
        ->call('sortTable', 'name', 'asc')
        ->call('updateResizableColumnWidth', 'created_at', '300')
        ->call('applySavedView', $view->getKey());

    expect(preferenceRow()->own_state->widths)->toBe([])
        ->and(preferenceRow()->own_state->sort)->toBe('name:asc');

    $component->call('applyOwnTable');

    expect($component->get('tableColumnWidths'))->toBe(['created_at' => 300])
        ->and($view->refresh()->state->widths)->toBe([]);
});

it('leaves the member\'s own table alone when another view replaces the active one', function (): void {
    $first = viewFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $second = viewFor($this->user, ['name' => 'By name', 'state' => new TableStateData(sort: 'name:desc')]);
    $component = livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applySavedView', $first->getKey());

    $component->call('applySavedView', $second->getKey());

    expect(preferenceRow()->own_state->sort)->toBe('name:asc');

    $component->call('applyOwnTable');

    expect($component->get('tableSort'))->toBe('name:asc');
});

it('keeps the member\'s own table when a new session opens on their default view', function (): void {
    $default = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('reorderViews', [$default->getKey()])->call('sortTable', 'name', 'asc');
    expect(preferenceRow()->active_view_id)->toBeNull();

    session()->flush();
    $nextSession = livewire(ListCompanies::class);

    expect($nextSession->get('activeSavedViewId'))->toBe($default->getKey())
        ->and($nextSession->get('tableSort'))->toBe('created_at:desc')
        ->and(preferenceRow()->own_state->sort)->toBe('name:asc');

    expect($nextSession->call('applyOwnTable')->get('tableSort'))->toBe('name:asc');
});

it('keeps the member\'s own table when a new session opens on a default over a changed view', function (): void {
    $default = viewFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $other = viewFor($this->user, ['name' => 'By name', 'state' => new TableStateData(sort: 'name:desc')]);
    livewire(ListCompanies::class)
        ->call('sortTable', 'name', 'asc')
        ->call('applySavedView', $other->getKey())
        ->call('reorderViews', [$default->getKey()])
        ->call('sortTable', 'created_at', 'asc');

    session()->flush();
    livewire(ListCompanies::class);

    expect(preferenceRow()->own_state->sort)->toBe('name:asc');
});

it('makes what the table shows the member\'s own table when the active view is deleted elsewhere', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applySavedView', $view->getKey());
    expect(preferenceRow()->own_state->sort)->toBe('name:asc');

    resolve(DeleteSavedView::class)->execute($this->user, $view);
    $component->call('applyOwnTable');

    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->get('activeSavedViewId'))->toBeNull();

    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('activeSavedViewId'))->toBeNull()
        ->and($revisit->get('tableSort'))->toBe('created_at:desc');
});

it('returns to the table shown after the active view went away, not to the older one', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $other = viewFor($this->user, ['name' => 'By name', 'state' => new TableStateData(sort: 'name:desc')]);
    $component = livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applySavedView', $view->getKey());

    resolve(DeleteSavedView::class)->execute($this->user, $view);
    $component->call('applySavedView', $other->getKey());

    expect(preferenceRow()->own_state->sort)->toBe('created_at:desc');

    $component->call('applyOwnTable');

    expect($component->get('tableSort'))->toBe('created_at:desc');
});

it('opens a new session without a parked table once the active view went away, even with a default view set', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $default = viewFor($this->user, ['name' => 'By name', 'state' => new TableStateData(sort: 'name:desc')]);
    $component = livewire(ListCompanies::class)
        ->call('sortTable', 'name', 'asc')
        ->call('reorderViews', [$default->getKey()])
        ->call('applySavedView', $view->getKey());

    resolve(DeleteSavedView::class)->execute($this->user, $view);
    session()->flush();
    $nextSession = livewire(ListCompanies::class);

    expect($nextSession->get('activeSavedViewId'))->toBe($default->getKey())
        ->and(preferenceRow()->own_state->sort)->toBe('created_at:desc');

    expect($nextSession->call('applyOwnTable')->get('tableSort'))->toBe('created_at:desc');
});

it('does not bring back an older own table on a second return to the built-in list', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);

    $component = livewire(ListCompanies::class)
        ->call('sortTable', 'name', 'asc')
        ->call('applySavedView', $view->getKey())
        ->call('applyOwnTable')
        ->call('sortTable', 'name', 'desc')
        ->call('applyOwnTable');

    expect($component->get('tableSort'))->toBe('name:desc')
        ->and(preferenceRow()->own_state)->toBeNull();
});

it('gives a second tab the table the first tab returned to', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $first = livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applySavedView', $view->getKey());
    $second = livewire(ListCompanies::class);
    expect($second->get('activeSavedViewId'))->toBe($view->getKey());

    $first->call('applyOwnTable')->call('sortTable', 'name', 'desc');
    $second->call('applyOwnTable');

    expect($second->get('tableSort'))->toBe('name:desc');
});

it('clears a view that vanished elsewhere when the member returns to their own table, and leaves the table as it is', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applySavedView', $view->getKey());
    expect(preferenceRow()->own_state)->not->toBeNull();

    resolve(DeleteSavedView::class)->execute($this->user, $view);
    $component->call('applyOwnTable');

    expect($component->get('activeSavedViewId'))->toBeNull()
        ->and($component->get('appliedSavedViewHash'))->toBeNull()
        ->and($component->get('tableSort'))->toBe('created_at:desc')
        ->and(preferenceRow()->own_state)->toBeNull()
        ->and(preferenceRow()->active_view_id)->toBeNull();
    $component->assertNotNotified();
});

it('does nothing when the built-in list is picked and no view is applied', function (): void {
    $component = livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applyOwnTable');

    expect($component->get('tableSort'))->toBe('name:asc');
});

it('opens a view named in the link', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);

    $component = Livewire::withQueryParams(['view' => $view->getKey()])->test(ListCompanies::class);

    expect($component->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->get('requestedSavedViewId'))->toBeNull()
        ->and(preferenceRow()->active_view_id)->toBe($view->getKey());
});

it('keeps the member\'s own table when a link opens a view', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('sortTable', 'name', 'asc');

    $component = Livewire::withQueryParams(['view' => $view->getKey()])->test(ListCompanies::class);

    expect(preferenceRow()->own_state->sort)->toBe('name:asc');
    expect($component->call('applyOwnTable')->get('tableSort'))->toBe('name:asc');
});

it('warns that a view named in the link is unavailable, and opens nothing and writes nothing', function (string $which): void {
    $stranger = User::factory()->withWorkspace()->create();
    $id = match ($which) {
        'another workspace' => SavedView::factory()->for($stranger)->for($stranger->currentWorkspace)->create()->getKey(),
        'another member\'s view' => viewFor(savedViewMember(WorkspaceRole::Member))->getKey(),
        'another record type' => viewFor($this->user, ['entity' => CrmEntity::People])->getKey(),
        'a view that does not exist' => '01JZZZZZZZZZZZZZZZZZZZZZZZ',
        'a random string' => 'not-a-view',
    };

    $component = Livewire::withQueryParams(['view' => $id])->test(ListCompanies::class);

    expect($component->get('activeSavedViewId'))->toBeNull()
        ->and($component->get('requestedSavedViewId'))->toBeNull()
        ->and(preferenceRow())->toBeNull();
    $component->assertOk()->assertNotified('That view is no longer available.');
})->with(['another workspace', 'another member\'s view', 'another record type', 'a view that does not exist', 'a random string']);

it('writes nothing when a link names the view that is already active and unchanged', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applySavedView', $view->getKey());
    $updatedAt = preferenceRow()->updated_at;

    $this->travelTo(now()->addHour());
    $component = Livewire::withQueryParams(['view' => $view->getKey()])->test(ListCompanies::class);

    expect($component->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($component->get('requestedSavedViewId'))->toBeNull()
        ->and(preferenceRow()->updated_at)->toEqual($updatedAt);
});

it('opens a link to a view the member already changed on the view as changed', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('sortTable', 'name', 'asc');

    $component = Livewire::withQueryParams(['view' => $view->getKey()])->test(ListCompanies::class);

    expect($component->get('tableSort'))->toBe('name:asc')
        ->and($view->refresh()->state->sort)->toBe('name:asc');
});

it('writes nothing when a table opens without a link', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applySavedView', $view->getKey());
    $updatedAt = preferenceRow()->updated_at;

    $this->travelTo(now()->addHour());
    livewire(ListCompanies::class);

    expect(preferenceRow()->own_state->sort)->toBe('name:asc')
        ->and(preferenceRow()->updated_at)->toEqual($updatedAt);
});

it('names the active view on the switcher and the built-in list when none is applied', function (): void {
    $view = viewFor($this->user, ['name' => 'Hot leads']);

    livewire(ListCompanies::class)
        ->assertSeeHtml('data-active-view="All companies"')
        ->assertSeeHtml('aria-label="All companies, switch view"')
        ->call('applySavedView', $view->getKey())
        ->assertSeeHtml('data-active-view="Hot leads"')
        ->assertSeeHtml('aria-label="Hot leads, switch view"');
});

it('lists the fixed entries a resource has', function (string $page, array $shown, array $hidden): void {
    Livewire::withQueryParams(['view' => SavedView::BUILT_IN_LIST_LINK])->test($page)->assertSeeText($shown)->assertDontSeeText($hidden);
})->with([
    'companies' => [ListCompanies::class, ['All companies'], ['Board', 'Cards', 'Pipeline']],
    'opportunities' => [ListOpportunities::class, ['All opportunities', 'Pipeline'], ['Cards', 'Board']],
    'notes' => [ManageNotes::class, ['All notes', 'Cards'], ['Board', 'Pipeline']],
]);

it('offers duplicate and the row actions on every view', function (): void {
    $first = viewFor($this->user, ['name' => 'Team pipeline']);
    $second = viewFor($this->user, ['name' => 'Just me']);

    livewire(ListCompanies::class)
        ->assertSeeText('Duplicate')
        ->assertSeeHtml('data-view-key="'.$first->getKey().'"')
        ->assertSeeHtml('data-view-key="'.$second->getKey().'"');
});

it('dates a view in the member zone', function (): void {
    viewFor($this->user, ['name' => 'Just me', 'created_at' => '2026-11-03 12:00:00']);

    livewire(ListCompanies::class)->assertSeeHtml('Created on Nov 3, 2026');
});

it('links a saved view from the board to the list page', function (): void {
    $view = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Open pipeline']);

    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml('view='.$view->getKey())
        ->assertSeeHtml('data-active-view="Pipeline"');
});

it('lists no view of another record type on the board', function (): void {
    viewFor($this->user, ['entity' => CrmEntity::Company, 'name' => 'Company only']);

    livewire(OpportunitiesBoard::class)->assertDontSeeText('Company only');
});

it('escapes a view name that carries markup, quotes and apostrophes', function (): void {
    $viewName = 'It\'s "hot" <b>leads</b>';
    viewFor($this->user, ['name' => $viewName]);

    $component = livewire(ListCompanies::class)
        ->assertOk()
        ->assertSeeHtml(e($viewName))
        ->assertDontSeeHtml('<b>leads</b>');

    expect($component->html())->not->toContain('<b>');
});

it('opens the built-in list from a link and leaves the applied view behind', function (): void {
    $view = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Open pipeline', 'state' => hotLeads()]);
    Livewire::withQueryParams(['view' => $view->getKey()])->test(ListOpportunities::class);

    $component = Livewire::withQueryParams(['view' => SavedView::BUILT_IN_LIST_LINK])->test(ListOpportunities::class);

    expect($component->get('activeSavedViewId'))->toBeNull()
        ->and($component->get('requestedSavedViewId'))->toBe(SavedView::BUILT_IN_LIST_LINK)
        ->and(preferenceRow()->active_view_id)->toBeNull();
    $component->assertSeeHtml('data-active-view="All opportunities"')->assertNotNotified();
});

it('writes nothing when a link names the built-in list and no view is applied', function (): void {
    livewire(ListCompanies::class)->call('sortTable', 'name', 'asc');
    $updatedAt = preferenceRow()->updated_at;

    $this->travelTo(now()->addHour());
    $component = Livewire::withQueryParams(['view' => SavedView::BUILT_IN_LIST_LINK])->test(ListCompanies::class);

    expect($component->get('requestedSavedViewId'))->toBe(SavedView::BUILT_IN_LIST_LINK)
        ->and($component->get('tableSort'))->toBe('name:asc')
        ->and(preferenceRow()->updated_at)->toEqual($updatedAt);
    $component->assertNotNotified();
});

it('links the built-in list from the board with the sentinel', function (): void {
    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml('href="'.OpportunityResource::getUrl('index', ['view' => SavedView::BUILT_IN_LIST_LINK]).'"');
});

it('lists the board, the table and the views in the member\'s order on the board', function (): void {
    $alpha = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Alpha']);
    viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Beta']);

    livewire(OpportunitiesBoard::class)->assertSeeHtmlInOrder(['Pipeline', 'All opportunities', 'Alpha', 'Beta']);

    Livewire::withQueryParams(['view' => 'all'])->test(ListOpportunities::class)->call('reorderViews', [$alpha->getKey()]);

    livewire(OpportunitiesBoard::class)->assertSeeHtmlInOrder(['Alpha', 'Pipeline', 'All opportunities', 'Beta']);
});

it('opens the create dialog with its own title, button and note', function (): void {
    $component = livewire(ListCompanies::class)->mountAction('saveSavedViewAs');

    $action = $component->instance()->getMountedAction();

    expect($action->getModalHeading())->toBe('Create view')
        ->and($action->getModalSubmitAction()->getLabel())->toBe('Create view');
});

it('hands the sort control the direction a saved view sorts by', function (string $sort, string $direction): void {
    $view = viewFor($this->user, ['entity' => CrmEntity::Company, 'state' => new TableStateData(sort: $sort)]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());

    expect($component->get('tableSort'))->toBe($sort)
        ->and($component->instance()->getFilterBarData()['sorts'][0])->toMatchArray(['column' => 'name', 'direction' => $direction]);
})->with([
    'descending' => ['name:desc', 'desc'],
    'ascending' => ['name:asc', 'asc'],
]);

it('opens a view sorted by several columns with every sort and unmodified', function (): void {
    $view = viewFor($this->user, ['entity' => CrmEntity::Company, 'state' => new TableStateData(sort: 'name:desc,created_at:asc')]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())
        ->assertSet('tableSort', 'name:desc')
        ->assertSet('tableThenSorts', 'created_at:asc');

    expect(array_column($component->instance()->getFilterBarData()['sorts'], 'direction', 'column'))->toBe(['name' => 'desc', 'created_at' => 'asc'])
        ->and($view->refresh()->state->sort)->toBe('name:desc,created_at:asc');

    $component->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'asc'], ['column' => 'name', 'direction' => 'desc']]);

    expect($view->refresh()->state->sort)->toBe('created_at:asc,name:desc');
});

function orderFor(CrmEntity $entity): ?array
{
    return TablePreference::query()->withoutGlobalScopes()->where('entity', $entity)->value('view_order');
}

it('lists the pipeline before the table for opportunities and the table first elsewhere when nothing is ordered', function (): void {
    viewFor($this->user, ['name' => 'Alpha']);
    viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Open']);

    livewire(OpportunitiesBoard::class)->assertSeeHtmlInOrder(['Pipeline', 'All opportunities', 'Open']);
    livewire(ListCompanies::class)->assertSeeHtmlInOrder(['All companies', 'Alpha']);
    livewire(ManageTasks::class)->assertSeeHtmlInOrder(['All tasks', 'Board']);
});

it('stores the order the member gives and keeps it apart from another member', function (): void {
    $first = viewFor($this->user, ['name' => 'First']);
    $second = viewFor($this->user, ['name' => 'Second']);

    resolve(ReorderViews::class)->execute($this->user, $this->workspace, CrmEntity::Company, [$second->getKey(), 'list', $first->getKey()]);

    expect(orderFor(CrmEntity::Company))->toBe([$second->getKey(), 'list', $first->getKey()]);

    $this->actingAs(savedViewMember(WorkspaceRole::Member));

    livewire(ListCompanies::class)->assertSeeHtmlInOrder(['All companies']);
    expect(orderFor(CrmEntity::Company))->toBe([$second->getKey(), 'list', $first->getKey()])
        ->and(TablePreference::query()->withoutGlobalScopes()->whereBelongsTo(auth()->user())->count())->toBe(0);
});

it('refuses an order that holds a view the member may not use', function (string $case): void {
    $stranger = User::factory()->withWorkspace()->create();
    $own = viewFor($this->user, ['name' => 'Mine']);
    $keys = match ($case) {
        'another member' => viewFor(savedViewMember(WorkspaceRole::Member), ['name' => 'Theirs'])->getKey(),
        'another workspace' => SavedView::factory()->for($stranger)->for($stranger->currentWorkspace)->create()->getKey(),
        'another entity' => viewFor($this->user, ['entity' => CrmEntity::People])->getKey(),
        'a page the entity lacks' => 'cards',
        'an unknown key' => 'nonsense',
    };

    expect(fn () => resolve(ReorderViews::class)->execute($this->user, $this->workspace, CrmEntity::Company, [$own->getKey(), $keys]))
        ->toThrow(HttpException::class)
        ->and(orderFor(CrmEntity::Company))->toBeNull();
})->with(['another member', 'another workspace', 'another entity', 'a page the entity lacks', 'an unknown key']);

it('refuses to order views for a workspace the member does not belong to', function (): void {
    $stranger = User::factory()->withWorkspace()->create();

    expect(fn () => resolve(ReorderViews::class)->execute($this->user, $stranger->currentWorkspace, CrmEntity::Company, ['list']))
        ->toThrow(HttpException::class);
});

it('drops a deleted view from the order and appends a new one at the end', function (): void {
    $gone = viewFor($this->user, ['name' => 'Gone']);
    $kept = viewFor($this->user, ['name' => 'Kept']);
    resolve(ReorderViews::class)->execute($this->user, $this->workspace, CrmEntity::Company, [$gone->getKey(), $kept->getKey(), 'list']);
    resolve(DeleteSavedView::class)->execute($this->user, $gone);
    viewFor($this->user, ['name' => 'Fresh']);

    livewire(ListCompanies::class)
        ->assertDontSeeText('Gone')
        ->assertSeeHtmlInOrder(['Kept', 'All companies', 'Fresh']);
});

it('reorders from the switcher and ignores a key that is no longer there', function (): void {
    $view = viewFor($this->user, ['name' => 'Alpha']);
    $gone = viewFor($this->user, ['name' => 'Gone']);
    $gone->delete();

    livewire(ListCompanies::class)->call('reorderViews', [$view->getKey(), $gone->getKey(), 'list'])->assertOk();

    expect(orderFor(CrmEntity::Company))->toBe([$view->getKey(), 'list']);
});

it('opens the board from the opportunities list when the board is first and the list when it is not', function (): void {
    $this->get(OpportunityResource::getUrl('index'))->assertRedirect(OpportunityResource::getUrl('board'));

    resolve(ReorderViews::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, ['list', 'board']);

    $this->get(OpportunityResource::getUrl('index'))->assertOk();
});

it('never redirects from the built-in list link, the board itself or a requested view', function (): void {
    $view = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Open']);

    $this->get(OpportunityResource::getUrl('index').'?view='.SavedView::BUILT_IN_LIST_LINK)->assertOk();
    $this->get(OpportunityResource::getUrl('index').'?view='.$view->getKey())->assertOk();
    $this->get(OpportunityResource::getUrl('board'))->assertOk();
});

it('applies the first view when it is a saved one and does not redirect', function (): void {
    $view = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Open', 'state' => hotLeads()]);
    resolve(ReorderViews::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, [$view->getKey()]);

    $this->get(OpportunityResource::getUrl('index'))->assertOk();

    livewire(ListOpportunities::class)
        ->assertSet('activeSavedViewId', $view->getKey())
        ->assertSet('tableSort', 'created_at:desc');
});

it('names the board Pipeline for opportunities and Board for tasks', function (): void {
    Livewire::withQueryParams(['view' => 'all'])->test(ListOpportunities::class)->assertSeeHtml('Pipeline')->assertDontSeeHtml('>Board<');
    livewire(ManageTasks::class)->assertSeeHtml('Board')->assertDontSeeHtml('Pipeline');
});

it('leaves a link that carries any query parameter on the page it names', function (string $resource, CrmEntity $entity, array $order, array $query): void {
    resolve(ReorderViews::class)->execute($this->user, $this->workspace, $entity, $order);

    $this->get($resource::getUrl('index').'?'.http_build_query($query))->assertOk();
})->with([
    'export' => [OpportunityResource::class, CrmEntity::Opportunity, ['board', 'list'], ['action' => 'export']],
    'filters' => [OpportunityResource::class, CrmEntity::Opportunity, ['board', 'list'], ['filters' => ['conditions' => ['match' => 'all']]]],
    'task' => [TaskResource::class, CrmEntity::Task, ['board', 'list'], ['task' => '01k0000000000000000000000z']],
    'assigned to me' => [TaskResource::class, CrmEntity::Task, ['board', 'list'], ['filters' => ['assigned_to_me' => ['isActive' => true]]]],
]);

it('leaves a note edit link on the cards page when the list is first', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create();
    resolve(ReorderViews::class)->execute($this->user, $this->workspace, CrmEntity::Note, ['list', 'cards']);

    $this->get(NoteResource::getUrl('index').'?'.http_build_query(['tableAction' => 'edit', 'tableActionRecord' => $note->getKey()]))->assertOk();
    $this->get(NoteResource::getUrl('index'))->assertRedirect(NoteResource::getUrl('list'));
});

it('opens notes on the cards for a member with no stored order', function (): void {
    $this->get(NoteResource::getUrl('index'))->assertOk();
});

it('serves the opportunities list for an empty view parameter and redirects a bare visit to the board', function (): void {
    $this->get(OpportunityResource::getUrl('index').'?view=')->assertOk();
    $this->get(OpportunityResource::getUrl('index'))->assertRedirect(OpportunityResource::getUrl('board'));
});

it('applies the first view on every bare visit, not only the first of a session', function (): void {
    $first = viewFor($this->user, ['name' => 'First', 'state' => hotLeads()]);
    $other = viewFor($this->user, ['name' => 'Other', 'state' => new TableStateData(sort: 'name:asc')]);
    resolve(ReorderViews::class)->execute($this->user, $this->workspace, CrmEntity::Company, [$first->getKey()]);

    expect(livewire(ListCompanies::class)->get('activeSavedViewId'))->toBe($first->getKey());

    livewire(ListCompanies::class)->call('applySavedView', $other->getKey());

    expect(livewire(ListCompanies::class)->get('activeSavedViewId'))->toBe($first->getKey())
        ->and(Livewire::withQueryParams(['view' => $other->getKey()])->test(ListCompanies::class)->get('activeSavedViewId'))->toBe($other->getKey());
});

it('points the sidebar at the index whatever page the member opened last', function (string $resource, string $board): void {
    $this->get($resource::getUrl($board))->assertOk();

    expect($resource::getNavigationUrl())->toBe($resource::getUrl('index'));
})->with([
    'opportunities' => [OpportunityResource::class, 'board'],
    'tasks' => [TaskResource::class, 'board'],
]);

it('opens the notes list from the cards page on a bare visit when the list comes first', function (): void {
    resolve(ReorderViews::class)->execute($this->user, $this->workspace, CrmEntity::Note, ['list', 'cards']);

    $this->get(NoteResource::getUrl('index'))->assertRedirect(NoteResource::getUrl('list'));

    resolve(ReorderViews::class)->execute($this->user, $this->workspace, CrmEntity::Note, ['cards', 'list']);

    $this->get(NoteResource::getUrl('index'))->assertOk();
});

it('opens the notes list from the cards page when a saved view comes first', function (): void {
    $view = viewFor($this->user, ['entity' => CrmEntity::Note, 'name' => 'Pinned']);
    resolve(ReorderViews::class)->execute($this->user, $this->workspace, CrmEntity::Note, [$view->getKey()]);

    $this->get(NoteResource::getUrl('index'))->assertRedirect(NoteResource::getUrl('list'));
});

it('leaves a cards link that names its page alone and links the cards from the list that way', function (): void {
    $this->get(NoteResource::getUrl('index').'?view=cards')->assertOk();

    openList(ManageNotes::class)->assertSeeHtml(e(NoteResource::getUrl('index', ['view' => 'cards'])));
});

it('stores a board view typed by the state it is given', function (): void {
    $state = new BoardStateData(stageOrder: ['b', 'a'], filters: ['conditions' => ['match' => 'all', 'rules' => []]], grouping: 'company');

    $view = resolve(CreateSavedView::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, 'Pipeline by company', $state);

    expect($view->fresh()->type)->toBe(SavedViewType::Board)
        ->and($view->fresh()->state)->toBeInstanceOf(BoardStateData::class)
        ->and($view->fresh()->boardState()->grouping)->toBe('company')
        ->and(fn () => $view->fresh()->tableState())->toThrow(LogicException::class);
});

it('refuses a state of the other type on a view', function (): void {
    $view = viewFor($this->user);

    expect(fn () => resolve(UpdateSavedView::class)->execute($this->user, $view, ['state' => new BoardStateData]))
        ->toThrow(HttpException::class);
});

it('writes a board state to the member\'s own view and to nobody else\'s', function (): void {
    $mine = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'type' => SavedViewType::Board, 'state' => new BoardStateData(grouping: 'company')]);
    $other = savedViewMember(WorkspaceRole::Member);
    $theirs = viewFor($other, ['entity' => CrmEntity::Opportunity, 'type' => SavedViewType::Board, 'state' => new BoardStateData]);
    $filters = ['conditions' => ['match' => 'all', 'rules' => []]];

    $saved = resolve(SaveBoardState::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, ['filters' => $filters], $mine);

    expect($saved->grouping)->toBe('company')
        ->and($mine->fresh()->boardState()->grouping)->toBe('company')
        ->and($mine->fresh()->boardState()->filters)->toEqual($filters)
        ->and(fn () => resolve(SaveBoardState::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, ['grouping' => null], $theirs))->toThrow(HttpException::class)
        ->and($theirs->fresh()->boardState()->grouping)->toBeNull()
        ->and(fn () => resolve(SaveBoardState::class)->execute($this->user, $this->workspace, CrmEntity::Company, ['grouping' => null]))->toThrow(HttpException::class);
});

it('refuses a board state the member may not write', function (string $case): void {
    $mine = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'type' => SavedViewType::Board, 'state' => new BoardStateData(grouping: 'company')]);
    $stranger = User::factory()->withWorkspace()->create();
    $changes = ['grouping' => 'assignee'];

    [$user, $view] = match ($case) {
        'non member' => [$stranger, $mine],
        'view of another entity' => [$this->user, viewFor($this->user, ['entity' => CrmEntity::Task, 'type' => SavedViewType::Board, 'state' => new BoardStateData(grouping: 'company')])],
        'view of another workspace' => [$this->user, SavedView::factory()->for($this->user)->for($stranger->currentWorkspace)->create(['entity' => CrmEntity::Opportunity, 'type' => SavedViewType::Board, 'state' => new BoardStateData(grouping: 'company')])],
        'table view' => [$this->user, viewFor($this->user, ['entity' => CrmEntity::Opportunity])],
        'unknown key' => [$this->user, $mine],
    };

    if ($case === 'unknown key') {
        $changes = ['stageOrder' => []];
    }

    expect(fn () => resolve(SaveBoardState::class)->execute($user, $this->workspace, CrmEntity::Opportunity, $changes, $view))
        ->toThrow(HttpException::class);

    expect($view->fresh()->state->toArray())->toEqual($view->state->toArray())
        ->and($mine->fresh()->boardState()->grouping)->toBe('company')
        ->and(TablePreference::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['non member', 'view of another entity', 'view of another workspace', 'table view', 'unknown key']);

it('refuses a board state for an entity without a board', function (): void {
    expect(fn () => resolve(CreateSavedView::class)->execute($this->user, $this->workspace, CrmEntity::Company, 'Pipeline', new BoardStateData))
        ->toThrow(HttpException::class);

    expect(SavedView::query()->count())->toBe(0);

    $company = viewFor($this->user);
    expect(fn () => resolve(UpdateSavedView::class)->execute($this->user, $company, ['state' => new BoardStateData]))
        ->toThrow(HttpException::class);
});

function boardViewFor(User $owner, array $attributes = []): SavedView
{
    return viewFor($owner, ['entity' => CrmEntity::Opportunity, 'type' => SavedViewType::Board, 'state' => new BoardStateData(grouping: 'company'), ...$attributes]);
}

function boardViewFirst(): SavedView
{
    $view = boardViewFor(test()->user);
    resolve(ReorderViews::class)->execute(test()->user, test()->workspace, CrmEntity::Opportunity, [$view->getKey(), 'list', 'board']);

    return $view;
}

it('never applies a board view to the list, also when it is first in the order', function (): void {
    $view = boardViewFirst();
    $view->update(['state' => new BoardStateData(filters: ['conditions' => ['match' => 'all', 'rules' => [['field' => 'name', 'operator' => 'contains', 'value' => 'zzz']]]])]);
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    livewire(ListOpportunities::class)->set('tableSort', 'created_at:desc');
    $before = preferenceRow();

    $this->travelTo(now()->addHour());
    $component = livewire(ListOpportunities::class);

    expect($component->get('activeSavedViewId'))->toBeNull()
        ->and(preferenceRow()->updated_at)->toEqual($before->updated_at)
        ->and(preferenceRow()->active_view_id)->toBeNull()
        ->and(preferenceRow()->state->hash())->toBe($before->state->hash());
    Livewire::withQueryParams(['view' => SavedView::BUILT_IN_LIST_LINK])->test(ListOpportunities::class)->assertCanSeeTableRecords([$opportunity]);
});

it('sends a bare list visit to a board view that is first in the order', function (): void {
    $view = boardViewFirst();

    $this->get(OpportunityResource::getUrl('index'))->assertRedirect(OpportunityResource::getUrl('board', ['view' => $view->getKey()]));
});

it('sends a board view id on the list to the board', function (): void {
    $view = boardViewFor($this->user);
    $board = OpportunityResource::getUrl('board', ['view' => $view->getKey()]);

    Livewire::withQueryParams(['view' => $view->getKey()])->test(ListOpportunities::class)->assertRedirect($board);
    Livewire::withQueryParams(['view' => SavedView::BUILT_IN_LIST_LINK])->test(ListOpportunities::class)->call('applySavedView', $view->getKey())->assertRedirect($board);
});

it('lists a board view with the board icon and a link to the board', function (): void {
    $view = boardViewFor($this->user, ['name' => 'By company']);
    $table = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Open deals']);
    $board = OpportunityResource::getUrl('board', ['view' => $view->getKey()]);

    $html = Livewire::withQueryParams(['view' => SavedView::BUILT_IN_LIST_LINK])->test(ListOpportunities::class)->html();
    $row = fn (SavedView $v): string => preg_match('/data-view-key="'.$v->getKey().'".*?(?=data-view-key=|$)/s', $html, $m) === 1 ? $m[0] : '';

    expect($row($view))->toContain('href="'.$board.'"')
        ->and($row($view))->toContain('fi-view-tile-orange')
        ->and($row($view))->not->toContain("applySavedView('{$view->getKey()}')")
        ->and($row($table))->not->toContain('fi-view-tile-orange')
        ->and($row($table))->toContain("applySavedView('{$table->getKey()}')");
});

function makeOpportunityBoardInaccessible(): void
{
    CustomField::query()->forEntity(Opportunity::class)->where('code', CrmEntity::Opportunity->boardFieldCode())->update(['code' => 'retired_stage']);
    Once::flush();
}

function makeOpportunityBoardAccessible(): void
{
    CustomField::query()->forEntity(Opportunity::class)->where('code', 'retired_stage')->update(['code' => CrmEntity::Opportunity->boardFieldCode()]);
    Once::flush();
}

it('offers no board views while the board page is not accessible', function (): void {
    $view = boardViewFirst();
    makeOpportunityBoardInaccessible();

    livewire(ListOpportunities::class)->assertOk()->assertDontSeeHtml('data-view-key="'.$view->getKey().'"');
    $this->get(OpportunityResource::getUrl('index'))->assertOk();
});

it('keeps a hidden board view in place when the visible rows are reordered', function (): void {
    $view = boardViewFirst();
    $table = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Open deals']);
    makeOpportunityBoardInaccessible();

    livewire(ListOpportunities::class)->call('reorderViews', [$table->getKey(), 'list']);
    makeOpportunityBoardAccessible();

    expect(orderFor(CrmEntity::Opportunity))->toBe([$view->getKey(), $table->getKey(), 'board', 'list']);
});
