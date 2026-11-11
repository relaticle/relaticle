<?php

declare(strict_types=1);

use App\Actions\Views\CreateSavedView;
use App\Actions\Views\DeleteSavedView;
use App\Actions\Views\DuplicateSavedView;
use App\Actions\Views\EnsureSavedViewNameIsFree;
use App\Actions\Views\SetDefaultSavedView;
use App\Actions\Views\UpdateSavedView;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\SavedViewType;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\HasSavedViews;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\User;
use App\Policies\SavedViewPolicy;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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

    $component = livewire($page)->call('applySavedView', $view->getKey());

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
        'default_view_id' => null,
        'applied_view_hash' => hash('xxh128', json_encode(sortedKeys([...$state->toArray(), 'search' => null, 'columnSearches' => []]), JSON_THROW_ON_ERROR)),
    ]);
    session()->put("tables.opened.{$this->workspace->getKey()}.company", true);
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

it('writes no view when the member returns to the built-in list', function (string $return): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $updatedAt = $view->updated_at;
    $this->travelTo(now()->addHour());

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call($return);

    expect($component->get('activeSavedViewId'))->toBeNull()
        ->and($view->refresh()->updated_at)->toEqual($updatedAt)
        ->and($view->state->sort)->toBe('created_at:desc');
})->with(['applyOwnTable', 'useStandardTable']);

it('writes nothing to another view when the active view is deleted', function (): void {
    $active = viewFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $other = viewFor($this->user, ['name' => 'By name', 'state' => new TableStateData(sort: 'name:asc')]);
    $updatedAt = $other->updated_at;
    $this->travelTo(now()->addHour());

    livewire(ListCompanies::class)
        ->call('applySavedView', $active->getKey())
        ->callAction('deleteSavedView', arguments: ['view' => $active->getKey()])
        ->call('sortTable', 'created_at', 'asc');

    expect(SavedView::query()->withoutGlobalScopes()->pluck('name')->all())->toBe(['By name'])
        ->and($other->refresh()->updated_at)->toEqual($updatedAt)
        ->and($other->state->sort)->toBe('name:asc');
});

it('renders no discard or save control on a changed view', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);

    livewire(ListCompanies::class)
        ->call('applySavedView', $view->getKey())
        ->call('sortTable', 'name', 'asc')
        ->assertDontSeeText(['Discard changes', 'Save as new view', 'More ways to save'])
        ->assertDontSeeHtml(['data-view-save', 'revertSavedView', "mountAction('saveSavedView')"]);
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

it('brings back the last state within a session, and the view as it was left in the next one', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('toggleDefaultSavedView', $view->getKey())->call('sortTable', 'name', 'asc');

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
    livewire(ListCompanies::class)->call('toggleDefaultSavedView', $default->getKey())->call('applySavedView', $other->getKey());

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
    TablePreference::factory()->for($this->user)->for($this->workspace)->create(['default_view_id' => $view->getKey()]);
    $updatedAt = preferenceRow()->updated_at;
    $viewUpdatedAt = $view->updated_at;

    $this->travelTo(now()->addHour());
    $component = livewire(ListCompanies::class);

    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->get('activeSavedViewId'))->toBe($view->getKey())
        ->and($view->refresh()->updated_at)->toEqual($viewUpdatedAt)
        ->and(preferenceRow()->updated_at)->toEqual($updatedAt);
});

it('switches back to the standard view from a saved one', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->call('useStandardTable');

    expect($component->get('tableSort'))->toBeNull()
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
        ->assertActionHidden('deleteSavedView');
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
        ->callAction('deleteSavedView', arguments: ['view' => $other->getKey()])
        ->assertSet('activeSavedViewId', $active->getKey())
        ->assertSet('defaultSavedViewId', null)
        ->assertSet('tableSort', 'created_at:desc');

    expect(SavedView::query()->withoutGlobalScopes()->pluck('name')->all())->toBe(['Hot leads']);
});

it('marks the view the table is showing, and only that one', function (): void {
    $view = viewFor($this->user, ['name' => 'Team pipeline']);

    $component = livewire(ListCompanies::class)
        ->assertSeeHtmlInOrder(['Team pipeline', 'aria-current="true"', 'All companies'])
        ->call('applySavedView', $view->getKey())
        ->assertSeeHtmlInOrder(['aria-current="true"', 'Team pipeline', 'All companies']);

    expect(substr_count($component->html(), 'aria-current="true"'))->toBe(1);
});

it('labels the view that opens by default and no other row', function (): void {
    $default = viewFor($this->user, ['name' => 'Team pipeline']);
    viewFor($this->user, ['name' => 'Just me']);

    $component = livewire(ListCompanies::class)
        ->assertDontSeeHtml('fi-ta-views-tag')
        ->call('toggleDefaultSavedView', $default->getKey())
        ->assertSeeHtmlInOrder(['Just me', 'Team pipeline', 'fi-ta-views-tag', 'Default']);

    expect(substr_count($component->html(), 'fi-ta-views-tag'))->toBe(1);
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
        ->call('toggleDefaultSavedView', $foreign->getKey())
        ->assertNotified('That view is no longer available.')
        ->assertSet('defaultSavedViewId', null);

    expect($foreign->refresh()->name)->toBe('Elsewhere')
        ->and(preferenceRow()?->default_view_id)->toBeNull();
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

it('offers a search box only once a member has seven views to choose from', function (): void {
    SavedView::factory()->for($this->user)->for($this->workspace)->count(6)->sequence(fn ($sequence): array => ['name' => "View {$sequence->index}"])->create();

    livewire(ListCompanies::class)->assertDontSeeHtml('placeholder="Search views"');

    viewFor($this->user, ['name' => 'Seventh']);

    livewire(ListCompanies::class)->assertSeeHtml('placeholder="Search views"');
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

it('keeps the member\'s own table across sessions while a view is applied', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applySavedView', $view->getKey());

    session()->flush();

    expect(livewire(ListCompanies::class)->call('applyOwnTable')->get('tableSort'))->toBe('name:asc');
});

it('forgets the member\'s own table on a reset to the default', function (): void {
    $view = viewFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('sortTable', 'name', 'asc')->call('applySavedView', $view->getKey());

    $component->call('useStandardTable');
    expect(preferenceRow()->own_state)->toBeNull();

    $component->call('applySavedView', $view->getKey())->call('applyOwnTable');

    expect($component->get('tableSort'))->toBeNull();
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
    livewire(ListCompanies::class)->call('toggleDefaultSavedView', $default->getKey())->call('sortTable', 'name', 'asc');
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
        ->call('toggleDefaultSavedView', $default->getKey())
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
        ->call('toggleDefaultSavedView', $default->getKey())
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

it('clears a parked table left on a row with no view when the member resets to the default', function (): void {
    TablePreference::factory()->for($this->user)->for($this->workspace)->create(['own_state' => new TableStateData(sort: 'name:asc')]);

    livewire(ListCompanies::class)->call('useStandardTable');

    expect(preferenceRow()->own_state)->toBeNull()
        ->and(preferenceRow()->active_view_id)->toBeNull();
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
    livewire($page)->assertSeeText($shown)->assertDontSeeText($hidden);
})->with([
    'companies' => [ListCompanies::class, ['All companies'], ['Board', 'Cards']],
    'opportunities' => [ListOpportunities::class, ['All opportunities', 'Board'], ['Cards']],
    'notes' => [ManageNotes::class, ['All notes', 'Cards'], ['Board']],
]);

it('offers duplicate and the row actions on every view', function (): void {
    $first = viewFor($this->user, ['name' => 'Team pipeline']);
    $second = viewFor($this->user, ['name' => 'Just me']);

    livewire(ListCompanies::class)
        ->assertSeeText('Duplicate')
        ->assertSeeHtml('data-view-id="'.$first->getKey().'" data-can-update="true"')
        ->assertSeeHtml('data-view-id="'.$second->getKey().'" data-can-update="true"');
});

it('dates a view in the member zone', function (): void {
    viewFor($this->user, ['name' => 'Just me', 'created_at' => '2026-11-03 12:00:00']);

    livewire(ListCompanies::class)->assertSeeHtml('Created on Nov 3, 2026');
});

it('links a saved view from the board to the list page', function (): void {
    $view = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Open pipeline']);

    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml('view='.$view->getKey())
        ->assertSeeHtml('data-active-view="Board"');
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
    livewire(ListOpportunities::class)->call('applySavedView', $view->getKey());

    $component = Livewire::withQueryParams(['view' => SavedView::BUILT_IN_LIST_LINK])->test(ListOpportunities::class);

    expect($component->get('activeSavedViewId'))->toBeNull()
        ->and($component->get('requestedSavedViewId'))->toBeNull()
        ->and(preferenceRow()->active_view_id)->toBeNull();
    $component->assertSeeHtml('data-active-view="All opportunities"')->assertNotNotified();
});

it('writes nothing when a link names the built-in list and no view is applied', function (): void {
    livewire(ListCompanies::class)->call('sortTable', 'name', 'asc');
    $updatedAt = preferenceRow()->updated_at;

    $this->travelTo(now()->addHour());
    $component = Livewire::withQueryParams(['view' => SavedView::BUILT_IN_LIST_LINK])->test(ListCompanies::class);

    expect($component->get('requestedSavedViewId'))->toBeNull()
        ->and($component->get('tableSort'))->toBe('name:asc')
        ->and(preferenceRow()->updated_at)->toEqual($updatedAt);
    $component->assertNotNotified();
});

it('links the built-in list from the board with the sentinel', function (): void {
    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml('href="'.OpportunityResource::getUrl('index', ['view' => SavedView::BUILT_IN_LIST_LINK]).'"');
});

it('tags the member\'s default view on the board and no other row', function (): void {
    $alpha = viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Alpha']);
    viewFor($this->user, ['entity' => CrmEntity::Opportunity, 'name' => 'Beta']);
    livewire(ListOpportunities::class)->call('toggleDefaultSavedView', $alpha->getKey());

    $component = livewire(OpportunitiesBoard::class)->assertSeeHtmlInOrder(['Alpha', 'fi-ta-views-tag', 'Beta']);

    expect(substr_count($component->html(), 'fi-ta-views-tag'))->toBe(1);
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
