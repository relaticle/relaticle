<?php

declare(strict_types=1);

use App\Actions\TableLayouts\CreateTableLayout;
use App\Actions\TableLayouts\DeleteTableLayout;
use App\Actions\TableLayouts\EnsureTableLayoutNameIsFree;
use App\Actions\TableLayouts\SetDefaultTableLayout;
use App\Actions\TableLayouts\UpdateTableLayout;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\HasTableLayouts;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Models\Notification;
use App\Models\TableLayout;
use App\Models\TablePreference;
use App\Models\User;
use App\Notifications\TableLayoutChangedNotification;
use App\Policies\TableLayoutPolicy;
use Filament\Facades\Filament;

mutates(
    HasTableLayouts::class,
    PersistsTableState::class,
    TableLayoutPolicy::class,
    CreateTableLayout::class,
    UpdateTableLayout::class,
    DeleteTableLayout::class,
    SetDefaultTableLayout::class,
    EnsureTableLayoutNameIsFree::class,
);

dataset('layout list pages', [
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

function tableLayoutMember(WorkspaceRole $role): User
{
    $member = User::factory()->create(['current_workspace_id' => test()->workspace->getKey()]);
    test()->workspace->users()->attach($member, ['role' => $role->value]);

    return $member;
}

function layoutFor(User $owner, array $attributes = []): TableLayout
{
    return TableLayout::factory()->for($owner)->for(test()->workspace)->create($attributes);
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
    $layout = layoutFor($this->user, ['entity' => $entity, 'state' => hotLeads()]);

    $component = livewire($page)->call('applySavedLayout', $layout->getKey());

    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->get('tableSearch'))->toBe('acme')
        ->and((int) $component->get('tableRecordsPerPage'))->toBe(50)
        ->and($component->get('tableFilters.trashed.value'))->toEqual(0)
        ->and($component->instance()->getTableFiltersForm()->getRawState()['trashed']['value'])->toEqual(0)
        ->and($component->get('tableColumns.1.name'))->toBe('created_at')
        ->and($component->get('tableColumns.1.isToggled'))->toBeFalse()
        ->and($component->instance()->isTableLayoutModified())->toBeFalse()
        ->and(preferenceRow()->active_layout_id)->toBe($layout->getKey());
})->with('layout list pages');

it('falls back to the default page size when a layout holds one the table does not offer', function (): void {
    $layout = layoutFor($this->user, ['state' => new TableStateData(perPage: 200)]);

    expect((int) livewire(ListCompanies::class)->set('tableRecordsPerPage', 50)->call('applySavedLayout', $layout->getKey())->get('tableRecordsPerPage'))->toBe(25);
});

it('applies a sort through the grouping a layout names', function (): void {
    $layout = layoutFor($this->user, ['state' => new TableStateData(sort: 'name:asc')]);

    $component = livewire(ListCompanies::class)->set('tableSearch', 'acme')->set('tableSort', 'created_at:desc')->call('applySavedLayout', $layout->getKey());

    expect($component->get('tableSearch'))->toBeEmpty()
        ->and($component->get('tableSort'))->toBe('name:asc');
});

it('saves the current table as a new layout without widths and applies it', function (): void {
    $component = livewire(ListCompanies::class)
        ->set('tableSort', 'created_at:desc')
        ->call('updateResizableColumnWidth', 'created_at', '300')
        ->callAction('saveTableLayoutAs', ['name' => 'Hot leads'])
        ->assertHasNoActionErrors()
        ->assertNotified('"Hot leads" saved as a new layout');

    $layout = TableLayout::query()->withoutGlobalScopes()->sole();

    expect($layout->name)->toBe('Hot leads')
        ->and($layout->is_shared)->toBeFalse()
        ->and($layout->state->sort)->toBe('created_at:desc')
        ->and($layout->state->widths)->toBe([])
        ->and($component->get('activeTableLayoutId'))->toBe($layout->getKey());

    $component->assertSeeText('Hot leads')->assertDontSeeText('(modified)');
});

it('refuses a layout name the member already uses, ignoring case', function (): void {
    layoutFor($this->user, ['name' => 'Hot leads']);

    livewire(ListCompanies::class)
        ->callAction('saveTableLayoutAs', ['name' => 'hot LEADS '])
        ->assertHasActionErrors(['name']);

    expect(TableLayout::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('lets two members use the same layout name', function (): void {
    layoutFor(tableLayoutMember(WorkspaceRole::Member), ['name' => 'Hot leads', 'is_shared' => true]);

    livewire(ListCompanies::class)->callAction('saveTableLayoutAs', ['name' => 'Hot leads'])->assertHasNoActionErrors();

    expect(TableLayout::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('marks a changed layout as modified, reverts it and saves over it', function (): void {
    $layout = layoutFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->set('tableSort', 'name:asc');

    expect($component->instance()->isTableLayoutModified())->toBeTrue();
    $component->assertSeeText('(modified)')->assertSeeHtml('wire:click="revertTableLayout"');

    $component->call('revertTableLayout');
    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->instance()->isTableLayoutModified())->toBeFalse();

    $component->set('tableSort', 'name:asc')->call('saveTableLayout');
    expect($layout->refresh()->state->sort)->toBe('name:asc')
        ->and($component->instance()->isTableLayoutModified())->toBeFalse();
});

it('ignores widths when telling whether a layout was changed', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);

    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->call('updateResizableColumnWidth', 'created_at', '300');

    expect($component->instance()->isTableLayoutModified())->toBeFalse();
});

it('brings back the last state over the default layout, and the modified mark with it', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->call('toggleDefaultTableLayout', $layout->getKey())->set('tableSort', 'name:asc');

    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('tableSort'))->toBe('name:asc')
        ->and($revisit->get('activeTableLayoutId'))->toBe($layout->getKey())
        ->and($revisit->instance()->isTableLayoutModified())->toBeTrue();
});

it('opens on the default layout when the member has no saved state, and writes nothing', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);
    TablePreference::factory()->for($this->user)->for($this->workspace)->create(['default_layout_id' => $layout->getKey()]);
    $updatedAt = preferenceRow()->updated_at;

    $this->travelTo(now()->addHour());
    $component = livewire(ListCompanies::class);

    expect($component->get('tableSort'))->toBe('created_at:desc')
        ->and($component->get('activeTableLayoutId'))->toBe($layout->getKey())
        ->and($component->instance()->isTableLayoutModified())->toBeFalse()
        ->and(preferenceRow()->updated_at)->toEqual($updatedAt);
});

it('switches back to the standard layout from a saved one', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->call('useStandardTableLayout');

    expect($component->get('tableSort'))->toBeNull()
        ->and($component->get('tableSearch'))->toBeEmpty()
        ->and((int) $component->get('tableRecordsPerPage'))->toBe(25)
        ->and($component->get('activeTableLayoutId'))->toBeNull()
        ->and(collect($component->get('tableColumns'))->firstWhere('name', 'created_at')['isToggled'])->toBeTrue();

    session()->flush();

    expect(livewire(ListCompanies::class)->get('tableSort'))->toBeNull();
});

it('offers the standard layout and nothing to manage when no layout is saved', function (): void {
    livewire(ListCompanies::class)
        ->assertSeeText('Standard layout')
        ->assertActionHidden('renameTableLayout')
        ->assertActionHidden('shareTableLayout')
        ->assertActionHidden('deleteTableLayout');
});

it('shares a layout only after the member confirms', function (): void {
    $layout = layoutFor($this->user, ['name' => 'Hot leads']);
    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->mountAction('shareTableLayout', ['layout' => $layout->getKey()]);

    expect($layout->refresh()->is_shared)->toBeFalse();
    $component->assertActionMounted('shareTableLayout');

    $component->callMountedAction();
    expect($layout->refresh()->is_shared)->toBeTrue();

    $component->mountAction('stopSharingTableLayout', ['layout' => $layout->getKey()]);
    expect($layout->refresh()->is_shared)->toBeFalse();
});

it('gives a member the new version of a shared layout they had not changed', function (): void {
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $layout = layoutFor($owner, ['is_shared' => true, 'state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey());

    resolve(UpdateTableLayout::class)->execute($owner, $layout, ['state' => new TableStateData(sort: 'name:asc')]);
    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('tableSort'))->toBe('name:asc')
        ->and($revisit->instance()->isTableLayoutModified())->toBeFalse();
});

it('keeps a member\'s changed copy when the shared layout changes under it', function (): void {
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $layout = layoutFor($owner, ['is_shared' => true, 'state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->set('tableSearch', 'mine');

    resolve(UpdateTableLayout::class)->execute($owner, $layout, ['state' => new TableStateData(sort: 'name:asc')]);
    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect($revisit->get('tableSearch'))->toBe('mine')
        ->and($revisit->get('tableSort'))->toBe('created_at:desc')
        ->and($revisit->instance()->isTableLayoutModified())->toBeTrue();
});

it('clears other members\' pointers when a layout stops being shared, and keeps their table', function (): void {
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $layout = layoutFor($owner, ['is_shared' => true, 'state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->call('toggleDefaultTableLayout', $layout->getKey());

    resolve(UpdateTableLayout::class)->execute($owner, $layout, ['is_shared' => false]);
    session()->flush();
    $revisit = livewire(ListCompanies::class);

    expect(preferenceRow()->only('active_layout_id', 'default_layout_id', 'applied_layout_hash'))->toBe(['active_layout_id' => null, 'default_layout_id' => null, 'applied_layout_hash' => null])
        ->and($revisit->get('tableSort'))->toBe('created_at:desc')
        ->and($revisit->get('activeTableLayoutId'))->toBeNull();
});

it('keeps the table and clears the pointers when a layout is deleted', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);
    livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->call('toggleDefaultTableLayout', $layout->getKey())
        ->callAction('deleteTableLayout', arguments: ['layout' => $layout->getKey()])
        ->assertSet('activeTableLayoutId', null)
        ->assertSet('defaultTableLayoutId', null)
        ->assertSet('tableSort', 'created_at:desc');

    expect(TableLayout::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(preferenceRow()->active_layout_id)->toBeNull();
});

it('manages a layout from its own row without switching to it', function (): void {
    $active = layoutFor($this->user, ['name' => 'Hot leads', 'state' => hotLeads()]);
    $other = layoutFor($this->user, ['name' => 'Renewals']);

    livewire(ListCompanies::class)
        ->call('applySavedLayout', $active->getKey())
        ->callAction('renameTableLayout', ['name' => 'Renewals due'], ['layout' => $other->getKey()])
        ->call('toggleDefaultTableLayout', $other->getKey())
        ->assertSet('defaultTableLayoutId', $other->getKey())
        ->callAction('shareTableLayout', arguments: ['layout' => $other->getKey()])
        ->callAction('deleteTableLayout', arguments: ['layout' => $other->getKey()])
        ->assertSet('activeTableLayoutId', $active->getKey())
        ->assertSet('defaultTableLayoutId', null)
        ->assertSet('tableSort', 'created_at:desc');

    expect(TableLayout::query()->withoutGlobalScopes()->pluck('name')->all())->toBe(['Hot leads']);
});

it('marks the layout the table is showing, and only that one', function (): void {
    $layout = layoutFor($this->user, ['name' => 'Team pipeline']);

    $component = livewire(ListCompanies::class)
        ->assertSeeHtmlInOrder(['aria-current="true"', 'Standard layout', 'Team pipeline'])
        ->call('applySavedLayout', $layout->getKey())
        ->assertSeeHtmlInOrder(['Standard layout', 'aria-current="true"', 'Team pipeline']);

    expect(substr_count($component->html(), 'aria-current="true"'))->toBe(1);
});

it('labels the layout that opens by default and the layouts a member shares', function (): void {
    $shared = layoutFor($this->user, ['name' => 'Team pipeline', 'is_shared' => true]);
    layoutFor($this->user, ['name' => 'Just me']);

    $component = livewire(ListCompanies::class)
        ->assertSeeHtmlInOrder(['Standard layout', 'aria-label="Default"', 'Just me', 'Team pipeline', '<span>Shared</span>'])
        ->call('toggleDefaultTableLayout', $shared->getKey())
        ->assertSeeHtmlInOrder(['Standard layout', 'Just me', 'Team pipeline', 'aria-label="Default"', '<span>Shared</span>']);

    expect(substr_count($component->html(), 'aria-label="Default"'))->toBe(1)
        ->and(substr_count($component->html(), '<span>Shared</span>'))->toBe(1);
});

it('lists own layouts and layouts shared with the workspace, never someone else\'s private one', function (): void {
    $other = tableLayoutMember(WorkspaceRole::Member);
    $mine = layoutFor($this->user, ['name' => 'Mine']);
    layoutFor($other, ['name' => 'Renewals this month', 'is_shared' => true]);
    $private = layoutFor($other, ['name' => 'Private plan']);

    livewire(ListCompanies::class)
        ->assertSeeText(['Mine', 'Renewals this month', $other->name])
        ->assertDontSeeText('Private plan')
        ->assertSeeHtml("applySavedLayout('{$mine->getKey()}')")
        ->call('applySavedLayout', $private->getKey())
        ->assertNotFound();
});

it('keeps layouts apart per table and per workspace', function (): void {
    layoutFor($this->user, ['name' => 'People layout', 'entity' => CrmEntity::People]);
    TableLayout::factory()->for($this->user)->for(User::factory()->withWorkspace()->create()->currentWorkspace)->create(['name' => 'Elsewhere']);

    livewire(ListCompanies::class)->assertDontSeeText(['People layout', 'Elsewhere']);
});

it('lets any member, a viewer too, save and share a layout', function (): void {
    $viewer = tableLayoutMember(WorkspaceRole::Viewer);
    $this->actingAs($viewer);

    $component = livewire(ListCompanies::class)->callAction('saveTableLayoutAs', ['name' => 'Viewer layout']);
    $component->callAction('shareTableLayout', arguments: ['layout' => $component->get('activeTableLayoutId')]);

    expect(TableLayout::query()->withoutGlobalScopes()->sole()->is_shared)->toBeTrue();
});

it('lets a member apply but not change someone else\'s shared layout', function (): void {
    $layout = layoutFor(tableLayoutMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true]);
    $this->actingAs(tableLayoutMember(WorkspaceRole::Member));

    livewire(ListCompanies::class)
        ->call('applySavedLayout', $layout->getKey())
        ->assertActionHidden('renameTableLayout', ['layout' => $layout->getKey()])
        ->assertActionHidden('deleteTableLayout', ['layout' => $layout->getKey()])
        ->call('saveTableLayout')
        ->assertForbidden();
});

it('lets an admin rename and delete someone else\'s shared layout, but not see a private one', function (): void {
    $admin = tableLayoutMember(WorkspaceRole::Admin);
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $shared = layoutFor($owner, ['name' => 'Team', 'is_shared' => true]);
    $private = layoutFor($owner, ['name' => 'Private']);
    $this->actingAs($admin);

    livewire(ListCompanies::class)
        ->call('applySavedLayout', $shared->getKey())
        ->callAction('renameTableLayout', ['name' => 'Team pipeline'], ['layout' => $shared->getKey()])
        ->assertHasNoActionErrors();

    expect($shared->refresh()->name)->toBe('Team pipeline')
        ->and($admin->can('update', $private))->toBeFalse()
        ->and($admin->can('delete', $shared))->toBeTrue();

    livewire(ListCompanies::class)->call('applySavedLayout', $shared->getKey())->callAction('deleteTableLayout', arguments: ['layout' => $shared->getKey()]);

    expect(TableLayout::query()->withoutGlobalScopes()->whereKey($shared->getKey())->exists())->toBeFalse();
});

it('asks an admin to confirm before taking someone else\'s layout back to its owner', function (): void {
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $layout = layoutFor($owner, ['name' => 'Team', 'is_shared' => true]);
    $this->actingAs(tableLayoutMember(WorkspaceRole::Admin));

    $component = livewire(ListCompanies::class)
        ->mountAction('stopSharingTableLayout', ['layout' => $layout->getKey()])
        ->assertActionMounted('stopSharingTableLayout')
        ->assertMountedActionModalSee("It goes back to {$owner->name} as a private layout");

    expect($layout->refresh()->is_shared)->toBeTrue();

    $component->callMountedAction();

    expect($layout->refresh()->is_shared)->toBeFalse();
});

it('lets an admin who stops sharing someone else\'s layout keep working on the table', function (): void {
    $layout = layoutFor(tableLayoutMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true, 'state' => hotLeads()]);
    $this->actingAs(tableLayoutMember(WorkspaceRole::Admin));

    $component = livewire(ListCompanies::class)
        ->call('applySavedLayout', $layout->getKey())
        ->callAction('stopSharingTableLayout', arguments: ['layout' => $layout->getKey()])
        ->assertSet('activeTableLayoutId', null)
        ->set('tableSort', 'name:asc')
        ->call('revertTableLayout')
        ->assertOk()
        ->assertDontSeeText('(modified)');

    expect($component->get('tableSort'))->toBe('name:asc')
        ->and($layout->refresh()->is_shared)->toBeFalse();
});

it('lets the owner keep managing a layout after an admin edits it', function (): void {
    $layout = layoutFor($this->user, ['is_shared' => true]);

    expect($this->user->can('update', $layout))->toBeTrue()
        ->and(tableLayoutMember(WorkspaceRole::Viewer)->can('update', $layout))->toBeFalse()
        ->and(User::factory()->create()->can('view', $layout))->toBeFalse();
});

it('offers save as new beside a modified layout, even one the member cannot save', function (): void {
    $layout = layoutFor(tableLayoutMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true, 'state' => hotLeads()]);
    $this->actingAs(tableLayoutMember(WorkspaceRole::Member));

    $component = livewire(ListCompanies::class)
        ->call('applySavedLayout', $layout->getKey())
        ->assertDontSeeHtml('fi-ta-layouts-save-as')
        ->set('tableSort', 'name:asc')
        ->assertSeeHtml('fi-ta-layouts-save-as')
        ->assertDontSeeHtml('wire:click="saveTableLayout"')
        ->callAction('saveTableLayoutAs', ['name' => 'My team copy']);

    expect($component->get('activeTableLayoutId'))->not->toBe($layout->getKey())
        ->and($component->instance()->isTableLayoutModified())->toBeFalse()
        ->and($layout->refresh()->state->sort)->toBe('created_at:desc');
});

it('offers a search box only once a member has seven layouts to choose from', function (): void {
    TableLayout::factory()->for($this->user)->for($this->workspace)->count(6)->sequence(fn ($sequence): array => ['name' => "Layout {$sequence->index}"])->create();

    livewire(ListCompanies::class)->assertDontSeeHtml('placeholder="Search layouts"');

    layoutFor($this->user, ['name' => 'Seventh']);

    livewire(ListCompanies::class)->assertSeeHtml('placeholder="Search layouts"');
});

it('names the owner when an admin is about to delete someone else\'s layout', function (): void {
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $theirs = layoutFor($owner, ['name' => 'Team', 'is_shared' => true]);
    $admin = tableLayoutMember(WorkspaceRole::Admin);
    $mine = layoutFor($admin, ['name' => 'Mine']);
    $this->actingAs($admin);

    livewire(ListCompanies::class)
        ->mountAction('deleteTableLayout', ['layout' => $theirs->getKey()])
        ->assertMountedActionModalSee("It belongs to {$owner->name}, who will be told you deleted it.");

    livewire(ListCompanies::class)
        ->mountAction('deleteTableLayout', ['layout' => $mine->getKey()])
        ->assertMountedActionModalSee('Members who use this layout keep what their table shows.')
        ->assertMountedActionModalDontSee('It belongs to');
});

it('confirms a deleted layout', function (): void {
    $layout = layoutFor($this->user, ['name' => 'Renewals']);

    livewire(ListCompanies::class)
        ->callAction('deleteTableLayout', arguments: ['layout' => $layout->getKey()])
        ->assertNotified('"Renewals" deleted');
});

it('tells the owner when an admin deletes their layout', function (): void {
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $layout = layoutFor($owner, ['name' => 'Renewals', 'is_shared' => true]);
    $admin = tableLayoutMember(WorkspaceRole::Admin);
    $this->actingAs($admin);

    livewire(ListCompanies::class)->callAction('deleteTableLayout', arguments: ['layout' => $layout->getKey()]);

    $notice = $owner->notifications()->sole();

    expect(TableLayout::query()->withoutGlobalScopes()->whereKey($layout->getKey())->exists())->toBeFalse()
        ->and($notice->type)->toBe(TableLayoutChangedNotification::class)
        ->and($notice->actor_id)->toBe($admin->getKey())
        ->and($notice->subject_id)->toBe($this->workspace->getKey())
        ->and($notice->data)->toBe(['layout_name' => 'Renewals', 'entity' => CrmEntity::Company->value, 'change' => TableLayoutChangedNotification::DELETED]);
});

it('tells the owner when an admin stops sharing their layout', function (): void {
    $owner = tableLayoutMember(WorkspaceRole::Member);
    $layout = layoutFor($owner, ['name' => 'Renewals', 'is_shared' => true]);
    $this->actingAs(tableLayoutMember(WorkspaceRole::Admin));

    livewire(ListCompanies::class)->callAction('stopSharingTableLayout', arguments: ['layout' => $layout->getKey()]);

    expect($owner->notifications()->sole()->data)->toBe(['layout_name' => 'Renewals', 'entity' => CrmEntity::Company->value, 'change' => TableLayoutChangedNotification::UNSHARED]);
});

it('tells no one when a member deletes or stops sharing their own layout', function (): void {
    $kept = layoutFor($this->user, ['name' => 'Kept', 'is_shared' => true]);
    $deleted = layoutFor($this->user, ['name' => 'Deleted', 'is_shared' => true]);

    livewire(ListCompanies::class)
        ->mountAction('stopSharingTableLayout', ['layout' => $kept->getKey()])
        ->callAction('deleteTableLayout', arguments: ['layout' => $deleted->getKey()]);

    expect($kept->refresh()->is_shared)->toBeFalse()
        ->and(Notification::query()->count())->toBe(0);
});

it('tells no one when an admin renames or overwrites someone else\'s layout', function (): void {
    $layout = layoutFor(tableLayoutMember(WorkspaceRole::Member), ['name' => 'Team', 'is_shared' => true, 'state' => hotLeads()]);
    $this->actingAs(tableLayoutMember(WorkspaceRole::Admin));

    livewire(ListCompanies::class)
        ->callAction('renameTableLayout', ['name' => 'Team pipeline'], ['layout' => $layout->getKey()])
        ->call('applySavedLayout', $layout->getKey())
        ->set('tableSort', 'name:asc')
        ->call('saveTableLayout');

    expect($layout->refresh()->name)->toBe('Team pipeline')
        ->and($layout->state->sort)->toBe('name:asc')
        ->and(Notification::query()->count())->toBe(0);
});

it('tells the member instead of failing when the layout they revert was deleted elsewhere', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->set('tableSort', 'name:asc');

    $layout->delete();

    $component->call('revertTableLayout')
        ->assertNotified('That layout is no longer available.')
        ->assertSet('activeTableLayoutId', null)
        ->assertSet('appliedTableLayoutHash', null);

    expect($component->get('tableSort'))->toBe('name:asc');
});

it('tells the member instead of failing when the layout they save over was deleted elsewhere', function (): void {
    $layout = layoutFor($this->user, ['state' => hotLeads()]);
    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey())->set('tableSort', 'name:asc');

    $layout->delete();

    $component->call('saveTableLayout')
        ->assertNotified('That layout is no longer available.')
        ->assertSet('activeTableLayoutId', null);

    expect(TableLayout::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('shows the name error when a rename races another save to the same name', function (): void {
    $layout = layoutFor($this->user, ['name' => 'Old pipeline']);

    TableLayout::updating(function (): void {
        TableLayout::withoutEvents(function (): void {
            layoutFor(test()->user, ['name' => 'Hot leads']);
        });
    });

    livewire(ListCompanies::class)
        ->callAction('renameTableLayout', ['name' => 'Hot leads'], ['layout' => $layout->getKey()])
        ->assertHasActionErrors(['name']);

    expect($layout->refresh()->name)->toBe('Old pipeline');
});
