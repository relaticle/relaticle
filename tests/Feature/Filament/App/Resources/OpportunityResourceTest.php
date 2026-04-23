<?php

declare(strict_types=1);

use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Models\Opportunity;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

mutates(OpportunityResource::class);

beforeEach(function () {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('can render the index page', function (): void {
    livewire(ListOpportunities::class)
        ->assertOk();
});

it('can render the view page', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    livewire(ViewOpportunity::class, ['record' => $record->getKey()])
        ->assertOk();
});

it('can render `:dataset` column', function (string $column): void {
    livewire(ListOpportunities::class)
        ->assertCanRenderTableColumn($column);
})->with(['name', 'creator.name']);

it('cannot render `:dataset` column', function (string $column): void {
    livewire(ListOpportunities::class)
        ->assertCanNotRenderTableColumn($column);
})->with(['deleted_at', 'created_at', 'updated_at']);

it('has `:dataset` column', function (string $column): void {
    livewire(ListOpportunities::class)
        ->assertTableColumnExists($column);
})->with(['name', 'creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('shows `:dataset` column', function (string $column): void {
    livewire(ListOpportunities::class)
        ->assertTableColumnVisible($column);
})->with(['name', 'creator.name', 'deleted_at', 'created_at', 'updated_at']);

it('can sort `:dataset` column', function (string $column): void {
    $records = Opportunity::factory(3)->recycle([$this->user, $this->team])->create();

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
    $records = Opportunity::factory(3)->recycle([$this->user, $this->team])->create();
    $search = data_get($records->first(), $column);

    livewire(ListOpportunities::class)
        ->searchTable($search instanceof BackedEnum ? $search->value : $search)
        ->assertCanSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) === $search))
        ->assertCanNotSeeTableRecords($records->filter(fn (Model $record) => data_get($record, $column) !== $search));
})->with(['name', 'creator.name']);

it('cannot display trashed records by default', function (): void {
    $records = Opportunity::factory()->count(4)->recycle([$this->user, $this->team])->create();
    $trashedRecords = Opportunity::factory()->trashed()->count(6)->recycle([$this->user, $this->team])->create();

    livewire(ListOpportunities::class)
        ->assertCanSeeTableRecords($records)
        ->assertCanNotSeeTableRecords($trashedRecords)
        ->assertCountTableRecords(4);
});

it('can paginate records', function (): void {
    $records = Opportunity::factory(20)->recycle([$this->user, $this->team])->create();

    livewire(ListOpportunities::class)
        ->assertCanSeeTableRecords($records->take(10), inOrder: true)
        ->call('gotoPage', 2)
        ->assertCanSeeTableRecords($records->skip(10)->take(10), inOrder: true);
});

it('can bulk delete records', function (): void {
    $records = Opportunity::factory(5)->recycle([$this->user, $this->team])->create();

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
        'team_id' => $this->team->id,
    ]);
});

it('can edit an opportunity', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    livewire(ListOpportunities::class)
        ->callAction(TestAction::make('edit')->table($record), data: [
            'name' => 'Updated Opportunity',
        ])
        ->assertHasNoActionErrors();

    expect($record->refresh()->name)->toBe('Updated Opportunity');
});

it('can delete an opportunity', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->team])->create();

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

it('sets creator_id and team_id via observer when creating an opportunity', function (): void {
    livewire(ListOpportunities::class)
        ->callAction('create', data: [
            'name' => 'Observer Test Deal',
        ])
        ->assertHasNoActionErrors();

    $opportunity = Opportunity::query()->where('name', 'Observer Test Deal')->first();

    expect($opportunity->creator_id)->toBe($this->user->id)
        ->and($opportunity->team_id)->toBe($this->team->id);
});

it('authorizes team member to view and update own team opportunity', function (): void {
    $record = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    expect($this->user->can('view', $record))->toBeTrue()
        ->and($this->user->can('update', $record))->toBeTrue()
        ->and($this->user->can('delete', $record))->toBeTrue();
});

it('denies non-team-member from viewing another team opportunity', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    $this->actingAs($otherUser);
    $record = Opportunity::factory()->for($otherTeam)->create();
    $this->actingAs($this->user);

    expect($this->user->can('view', $record))->toBeFalse()
        ->and($this->user->can('update', $record))->toBeFalse()
        ->and($this->user->can('delete', $record))->toBeFalse();
});
