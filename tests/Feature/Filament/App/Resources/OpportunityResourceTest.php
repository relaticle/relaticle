<?php

declare(strict_types=1);

use App\Filament\Concerns\RendersRecordSplitView;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

mutates(OpportunityResource::class, ViewOpportunity::class, RendersRecordSplitView::class);

beforeEach(function () {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

it('can render the index page', function (): void {
    livewire(ListOpportunities::class)
        ->assertOk();
});

it('can render the view page', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->assertOk();
});

it('places opportunity details beside tasks notes and activity', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create([
        'name' => 'Acme',
    ]);
    $contact = People::factory()->recycle([$this->user, $this->workspace])->create([
        'name' => 'Ada Lovelace',
        'company_id' => $company->getKey(),
    ]);
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create([
        'name' => 'Enterprise rollout',
        'company_id' => $company->getKey(),
        'contact_id' => $contact->getKey(),
    ]);

    $page = livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->assertOk()
        ->assertSee('fi-record-split', false)
        ->assertSee('fi-record-details-rail', false)
        ->assertSee('fi-record-work-pane', false)
        ->assertSee('Enterprise rollout')
        ->assertSee('Acme')
        ->assertSee('Ada Lovelace')
        ->assertSee(__('filament/resources/task.navigation_label'))
        ->assertSee(__('filament/resources/note.navigation_label'))
        ->assertDontSee(__('filament/resources/opportunity.pages.view.actions.edit.label'))
        ->assertSee('fi-record-details-more', false)
        ->assertSee('fi-record-details-overflow-toggle', false)
        ->assertSee(__('filament/inline-edit.view_more'))
        ->assertDontSee('fi-record-work-more', false)
        ->assertSee('fi-in-entry-has-inline-label', false)
        ->assertActionExists(TestAction::make('copyPageUrl')->schemaComponent('opportunityDetails'))
        ->assertActionExists(TestAction::make('delete')->schemaComponent('opportunityDetails'));

    expect($page->instance()->getMaxContentWidth())->toBe(Width::Full);
});

// Column metadata is checked against a single mounted table rather than one
// dataset case per column: mounting the page dominates the cost, and Filament's
// assertion messages already name the offending column.
it('exposes the expected table columns', function (): void {
    $table = livewire(ListOpportunities::class);

    foreach (['name', 'creator.name', 'deleted_at', 'created_at', 'updated_at'] as $column) {
        $table->assertTableColumnExists($column);
    }

    foreach (['name', 'creator.name', 'deleted_at', 'created_at', 'updated_at'] as $column) {
        $table->assertTableColumnVisible($column);
    }

    foreach (['name', 'creator.name'] as $column) {
        $table->assertCanRenderTableColumn($column);
    }

    foreach (['deleted_at', 'created_at', 'updated_at'] as $column) {
        $table->assertCanNotRenderTableColumn($column);
    }
});

it('can sort `:dataset` column', function (string $column): void {
    $records = Opportunity::factory(3)->recycle([$this->user, $this->workspace])->create();

    $sortingKey = data_get($records->first(), $column) instanceof BackedEnum
        ? fn (Model $record) => data_get($record, $column)->value
        : $column;

    livewire(ListOpportunities::class)
        ->sortTable($column)
        ->assertCanSeeTableRecords($records->sortBy($sortingKey), inOrder: true)
        ->sortTable($column, 'desc')
        ->assertCanSeeTableRecords($records->sortByDesc($sortingKey), inOrder: true);
})->with(['creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('can search `:dataset` column', function (string $column): void {
    $records = Opportunity::factory(3)->recycle([$this->user, $this->workspace])->create();
    $search = data_get($records->first(), $column);

    livewire(ListOpportunities::class)
        ->searchTable($search instanceof BackedEnum ? $search->value : $search)
        ->assertCanSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) === $search))
        ->assertCanNotSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) !== $search));
})->with(['name', 'creator.name']);

it('cannot display trashed records by default', function (): void {
    $records = Opportunity::factory()->count(4)->recycle([$this->user, $this->workspace])->create();
    $trashedRecords = Opportunity::factory()->trashed()->count(6)->recycle([$this->user, $this->workspace])->create();

    livewire(ListOpportunities::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($trashedRecords)
        ->assertCountTableRecords(4);
});

it('can paginate records', function (): void {
    $records = Opportunity::factory(20)->recycle([$this->user, $this->workspace])->create();

    livewire(ListOpportunities::class)
        ->assertCanSeeTableRecords($records->take(10), inOrder: true)
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($records->skip(10)->take(10), inOrder: true);
});

it('can bulk delete records', function (): void {
    $records = Opportunity::factory(5)->recycle([$this->user, $this->workspace])->create();

    livewire(ListOpportunities::class)
        ->assertCanSeeTableRecords($records)
        ->selectTableRecords($records)
        // NOTE: Using direct action array instead of TestAction::make()->bulk()
        // because TestAction triggers unnecessary form building during bulk actions
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertNotified()
        ->assertCanNotSeeTableRecords($records);

    $this->assertSoftDeleted($records);
});

it('can create an opportunity', function (): void {
    livewire(ListOpportunities::class)
        ->callAction('create', data: [
            'name' => 'Big Deal',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas(Opportunity::class, [
        'name' => 'Big Deal',
        'workspace_id' => $this->workspace->id,
    ]);
});

it('can edit an opportunity', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ListOpportunities::class)
        ->callAction(TestAction::make('edit')->table($record), data: [
            'name' => 'Updated Opportunity',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->name)->toBe('Updated Opportunity');
});

it('can delete an opportunity', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ListOpportunities::class)
        ->callAction(TestAction::make('delete')->table($record));

    $this->assertSoftDeleted($record);
});

it('validates name is required on create', function (): void {
    livewire(ListOpportunities::class)
        ->callAction('create', data: [
            'name' => null,
        ])
        ->assertHasActionErrors(['name' => 'required']);
});

it('has `:dataset` filter', function (string $filter): void {
    livewire(ListOpportunities::class)
        ->assertTableFilterExists($filter);
})->with(['creation_source', 'trashed']);

it('sets creator_id and workspace_id via observer when creating an opportunity', function (): void {
    livewire(ListOpportunities::class)
        ->callAction('create', data: [
            'name' => 'Observer Test Deal',
        ])
        ->assertHasNoActionErrors();

    $opportunity = Opportunity::query()->where('name', 'Observer Test Deal')->first();

    expect($opportunity->creator_id)->toBe($this->user->id)
        ->and($opportunity->workspace_id)->toBe($this->workspace->id);
});

it('authorizes workspace member to view and update own workspace opportunity', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    expect($this->user->can('view', $record))->toBeTrue()
        ->and($this->user->can('update', $record))->toBeTrue()
        ->and($this->user->can('delete', $record))->toBeTrue();
});

it('denies non-workspace-member from viewing another workspace opportunity', function (): void {
    $otherUser = User::factory()->withWorkspace()->create();
    $otherWorkspace = $otherUser->currentWorkspace;

    $this->actingAs($otherUser);
    $record = Opportunity::factory()->for($otherWorkspace)->create();
    $this->actingAs($this->user);

    expect($this->user->can('view', $record))->toBeFalse()
        ->and($this->user->can('update', $record))->toBeFalse()
        ->and($this->user->can('delete', $record))->toBeFalse();
});

/**
 * The date-time table column converts to the viewer's zone, but a date-only field must
 * not: a bare close date has no time of day, so shifting it moves the day itself for
 * anyone west of UTC. Los Angeles is UTC-7 in August, so a naive conversion of
 * 2026-08-19 00:00 renders as the 18th.
 */
it('does not shift a date-only custom field into the user timezone', function (): void {
    $this->user->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $closeDateField = DB::table('custom_fields')
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'close_date')
        ->value('id');

    expect($closeDateField)->not->toBeNull();

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFields(['close_date' => '2026-08-19']);

    livewire(ListOpportunities::class)
        ->assertOk()
        ->assertSee('Aug 19, 2026')
        ->assertDontSee('Aug 18, 2026');
});
