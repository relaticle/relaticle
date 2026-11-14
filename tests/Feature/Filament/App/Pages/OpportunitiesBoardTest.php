<?php

declare(strict_types=1);

use App\Actions\CustomFields\SetCustomFieldOptions;
use App\Actions\Views\PlaceBoardCard;
use App\Actions\Views\ReorderBoardStages;
use App\Actions\Views\SetBoardColumnCollapsed;
use App\Actions\Views\SetBoardColumnField;
use App\Actions\Views\SetBoardColumnVisibility;
use App\Actions\Views\SetCardAttributes;
use App\Data\BoardStateData;
use App\Enums\BoardCardSize;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\OptionColor;
use App\Enums\SavedViewType;
use App\Enums\WorkspaceRole;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Actions\PreviewRecordAction;
use App\Filament\Concerns\HasBoardCards;
use App\Filament\Concerns\HasBoardStageOrder;
use App\Filament\Concerns\HasBoardViews;
use App\Filament\Concerns\HasViewSwitcher;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Tables\Concerns\CreatesTableAttribute;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\Task;
use App\Models\User;
use App\Queries\Views\BoardCardAttributesQuery;
use App\Queries\Views\BoardColumnFieldsQuery;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\Grouping;
use Relaticle\Flowforge\Lane;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Helpers\WorkspaceCustomField;

mutates(SetCardAttributes::class, PlaceBoardCard::class, CreatesTableAttribute::class, BoardCardAttributesQuery::class, HasBoardCards::class, ReorderBoardStages::class, SetBoardColumnField::class, SetBoardColumnCollapsed::class, SetBoardColumnVisibility::class, BoardColumnFieldsQuery::class, OpportunitiesBoard::class, MoveCardAction::class, PreviewRecordAction::class, HasBoardViews::class, HasBoardStageOrder::class, HasViewSwitcher::class);

beforeEach(function () {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);

    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->stageField = CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::STAGE)
        ->first();
});

function getOpportunityBoard(): Board
{
    $component = livewire(OpportunitiesBoard::class);

    return $component->instance()->getBoard();
}

it('can render the board page', function (): void {
    livewire(OpportunitiesBoard::class)
        ->assertOk();
});

it('reads the stored table preference of the member once per board render', function (): void {
    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        $reads += preg_match('/^select .* from "table_preferences"/', $query->sql);
    });

    livewire(OpportunitiesBoard::class)->assertOk();

    expect($reads)->toBe(1);
});

it('displays opportunities in the correct board columns', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $closedWon = $this->stageField->options->firstWhere('name', 'Closed Won');

    $prospectingOpportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $prospectingOpportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    $closedWonOpportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $closedWonOpportunity->saveCustomFieldValue($this->stageField, $closedWon->getKey());

    $board = getOpportunityBoard();

    expect($board->getBoardRecords((string) $prospecting->getKey())->pluck('id'))
        ->toContain($prospectingOpportunity->id)
        ->and($board->getBoardRecords((string) $closedWon->getKey())->pluck('id'))
        ->toContain($closedWonOpportunity->id);
});

it('renders the company entity icon on a board card', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    Opportunity::factory()
        ->recycle([$this->user, $this->workspace])
        ->create([
            'name' => 'Acme renewal',
            'company_id' => $company->getKey(),
            'custom_fields' => [$this->stageField->code => $prospecting->getKey()],
        ]);

    livewire(OpportunitiesBoard::class)
        ->assertSee('Acme Corp')
        ->assertSee(CrmEntity::Company->iconPath(), escape: false);
});

it('narrows the cards with a rule from the bar', function (string $field): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    $keep = opportunityInStage($this, 'Keep me', 'Prospecting', $acme);
    $drop = opportunityInStage($this, 'Drop me', 'Prospecting');
    $keep->saveCustomFieldValue(WorkspaceCustomField::byCode($this->workspace->getKey(), 'opportunity', 'amount'), 5000);

    $rule = match ($field) {
        'name' => ['field' => 'name', 'condition' => 'contains', 'value' => 'Keep'],
        'company' => ['field' => 'company', 'condition' => 'is_any_of', 'value' => [$acme->getKey()]],
        'custom_fields.amount' => ['field' => 'custom_fields.amount', 'condition' => 'greater_than', 'value' => 1000],
    };

    $component = livewire(OpportunitiesBoard::class);

    expect(opportunityStageCardIds($component, 'Prospecting'))->toContain($keep->id, $drop->id);

    $component->call('setFilterRules', [$rule]);

    expect(opportunityStageCardIds($component, 'Prospecting'))->toBe([$keep->id]);
})->with(['a native field' => 'name', 'a relation' => 'company', 'a custom attribute' => 'custom_fields.amount']);

it('shows every card again when the bar is cleared', function (): void {
    $keep = opportunityInStage($this, 'Keep me', 'Prospecting');
    $drop = opportunityInStage($this, 'Drop me', 'Prospecting');

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterRules', [['field' => 'name', 'condition' => 'contains', 'value' => 'Keep']]);

    expect($component->instance()->hasFilterBarState())->toBeTrue();

    $component->call('clearFilterBar');

    expect(opportunityStageCardIds($component, 'Prospecting'))->toContain($keep->id, $drop->id)
        ->and($component->instance()->hasFilterBarState())->toBeFalse();
});

it('makes company lanes when view settings groups by company', function (): void {
    livewire(OpportunitiesBoard::class)
        ->call('setBoardGrouping', 'company')
        ->assertSet('groupBy', 'company');
});

it('removes the lanes when the grouping is cleared', function (): void {
    livewire(OpportunitiesBoard::class)
        ->call('setBoardGrouping', 'company')
        ->call('setBoardGrouping', null)
        ->assertSet('groupBy', null);
});

it('ignores a grouping the board does not have', function (): void {
    livewire(OpportunitiesBoard::class)
        ->call('setBoardGrouping', 'stage')
        ->assertSet('groupBy', null);
});

it('offers the bar no deleted control and no groups, and lists the lanes in view settings', function (): void {
    $component = livewire(OpportunitiesBoard::class);
    $data = $component->instance()->getFilterBarData();

    expect($data['deletable'])->toBeFalse()
        ->and($data['groups'])->toBe([])
        ->and($data['grouping'])->toBeNull();

    $component->assertSeeHtml('data-board-grouping="company"')
        ->assertSeeHtml('data-board-grouping="none"')
        ->assertSee(__('filament/pages/boards.view_settings.lanes'))
        ->assertSee(__('filament/pages/boards.view_settings.no_lanes'));
});

it('marks the current lane grouping in view settings', function (): void {
    $none = livewire(OpportunitiesBoard::class)->html();
    $lanes = livewire(OpportunitiesBoard::class, ['groupBy' => 'company'])->html();

    expect($none)->toMatch('/aria-checked="true"[^>]*data-board-grouping="none"/s')
        ->and(preg_match('/aria-checked="true"[^>]*data-board-grouping="company"/s', $none))->toBe(0)
        ->and($lanes)->toMatch('/aria-checked="true"[^>]*data-board-grouping="company"/s')
        ->and(preg_match('/aria-checked="true"[^>]*data-board-grouping="none"/s', $lanes))->toBe(0);
});

function opportunitiesToSort(object $test): void
{
    $amount = WorkspaceCustomField::byCode($test->workspace->getKey(), 'opportunity', 'amount');

    foreach ([
        ['Bravo', Date::parse('2026-01-01'), 300, 1000],
        ['Charlie', Date::parse('2026-01-03'), 100, 2000],
        ['Alpha', Date::parse('2026-01-02'), 200, 3000],
    ] as [$name, $createdAt, $value, $position]) {
        $opportunity = opportunityInStage($test, $name, 'Prospecting');
        $opportunity->forceFill(['created_at' => $createdAt, 'order_column' => $position])->saveQuietly();
        $opportunity->saveCustomFieldValue($amount, $value);
    }
}

function opportunityStageNames(Testable $component, string $stage): array
{
    $option = CustomFieldOption::query()->withoutGlobalScopes()->where('name', $stage)->firstOrFail();

    return $component->instance()->getBoard()->getBoardRecords((string) $option->getKey())->pluck('name')->all();
}

it('orders the cards of a column by the sort from the bar', function (string $column, string $direction, array $expected): void {
    opportunitiesToSort($this);

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', [['column' => $column, 'direction' => $direction]]);

    expect(opportunityStageNames($component, 'Prospecting'))->toBe($expected);
})->with([
    'newest first' => ['created_at', 'desc', ['Charlie', 'Alpha', 'Bravo']],
    'oldest first' => ['created_at', 'asc', ['Bravo', 'Alpha', 'Charlie']],
    'name a to z' => ['name', 'asc', ['Alpha', 'Bravo', 'Charlie']],
    'largest amount first' => ['custom_fields.amount', 'desc', ['Bravo', 'Alpha', 'Charlie']],
    'smallest amount first' => ['custom_fields.amount', 'asc', ['Charlie', 'Alpha', 'Bravo']],
]);

it('keeps the hand order while no sort is set', function (): void {
    opportunitiesToSort($this);

    $component = livewire(OpportunitiesBoard::class);

    expect(opportunityStageNames($component, 'Prospecting'))->toBe(['Bravo', 'Charlie', 'Alpha']);

    $component->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'asc']])->call('setFilterBarSorts', []);

    expect(opportunityStageNames($component, 'Prospecting'))->toBe(['Bravo', 'Charlie', 'Alpha'])
        ->and($component->get('boardSort'))->toBeNull();
});

it('puts a card without a value last when sorting by a custom attribute', function (): void {
    opportunitiesToSort($this);
    opportunityInStage($this, 'Delta', 'Prospecting')->forceFill(['order_column' => 500])->saveQuietly();

    foreach (['asc', 'desc'] as $direction) {
        $component = livewire(OpportunitiesBoard::class)
            ->call('setFilterBarSorts', [['column' => 'custom_fields.amount', 'direction' => $direction]]);

        expect(last(opportunityStageNames($component, 'Prospecting')))->toBe('Delta');
    }
});

it('offers the sort fields the shared sort language allows', function (): void {
    $keys = array_column(livewire(OpportunitiesBoard::class)->instance()->getFilterBarData()['sortable'], 'key');

    expect($keys)->toContain('name', 'created_at', 'updated_at', 'custom_fields.amount', 'custom_fields.close_date')
        ->and($keys)->not->toContain('order_column');
});

it('gives each sort field the label and icon the filter catalog gives it', function (): void {
    $data = livewire(OpportunitiesBoard::class)->instance()->getFilterBarData();
    $fields = collect($data['fields'])->keyBy('key');

    foreach ($data['sortable'] as $sortable) {
        expect($fields->get($sortable['key']))->not->toBeNull()
            ->and($sortable['label'])->toBe($fields[$sortable['key']]['label'])
            ->and($sortable['icon'])->toBe($fields[$sortable['key']]['icon']);
    }
});

it('ignores a sort the board does not offer', function (array $sorts): void {
    livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', $sorts)
        ->assertSet('boardSort', null);
})->with([
    'a column the board does not offer' => [[['column' => 'order_column', 'direction' => 'asc']]],
    'a direction that is not one' => [[['column' => 'name', 'direction' => 'sideways']]],
    'not a list of sorts' => [['name']],
]);

it('reads the sort from the url and ignores a garbage one', function (): void {
    opportunitiesToSort($this);

    $sorted = Livewire::withQueryParams(['sort' => 'created_at:desc'])->test(OpportunitiesBoard::class);

    expect(opportunityStageNames($sorted, 'Prospecting'))->toBe(['Charlie', 'Alpha', 'Bravo'])
        ->and($sorted->instance()->getFilterBarData()['sorts'][0])->toMatchArray(['column' => 'created_at', 'direction' => 'desc']);

    foreach (['x;drop', 'order_column:asc', 'name:sideways', ':', 'custom_fields.gone:asc'] as $garbage) {
        $component = Livewire::withQueryParams(['sort' => $garbage])->test(OpportunitiesBoard::class)->assertOk();

        expect(opportunityStageNames($component, 'Prospecting'))->toBe(['Bravo', 'Charlie', 'Alpha'])
            ->and($component->instance()->getFilterBarData()['sorts'])->toBe([]);
    }
});

/**
 * @return array{Company, Company}
 */
function opportunitiesInCompanyLanes(object $test): array
{
    opportunitiesToSort($test);
    [$first, $second] = Company::factory()->count(2)->recycle([$test->user, $test->workspace])->create()->all();
    Opportunity::query()->update(['company_id' => $first->getKey()]);
    $amount = WorkspaceCustomField::byCode($test->workspace->getKey(), 'opportunity', 'amount');

    foreach ([
        ['Foxtrot', Date::parse('2026-01-04'), 250, 1000],
        ['Delta', Date::parse('2026-01-06'), 50, 2000],
        ['Echo', Date::parse('2026-01-05'), 400, 3000],
    ] as [$name, $createdAt, $value, $position]) {
        $opportunity = opportunityInStage($test, $name, 'Prospecting', $second);
        $opportunity->forceFill(['created_at' => $createdAt, 'order_column' => $position])->saveQuietly();
        $opportunity->saveCustomFieldValue($amount, $value);
    }

    return [$first, $second];
}

function opportunityLaneNames(Testable $component, Company $company): array
{
    $board = $component->instance()->getBoard();
    $lane = collect($board->getLanes($board->getActiveGrouping()))->first(fn (Lane $lane): bool => $lane->getName() === (string) $company->getKey());

    return $board->getLaneRecords($lane, 50)->pluck('name')->all();
}

it('keeps the hand order in lane cells while no sort is set', function (): void {
    [$first, $second] = opportunitiesInCompanyLanes($this);

    $component = livewire(OpportunitiesBoard::class)->call('setBoardGrouping', 'company');

    expect(opportunityLaneNames($component, $first))->toBe(['Bravo', 'Charlie', 'Alpha'])
        ->and(opportunityLaneNames($component, $second))->toBe(['Foxtrot', 'Delta', 'Echo']);
});

it('orders the cards of each lane cell by the sort from the bar', function (string $column, string $direction, array $firstLane, array $secondLane): void {
    [$first, $second] = opportunitiesInCompanyLanes($this);

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardGrouping', 'company')
        ->call('setFilterBarSorts', [['column' => $column, 'direction' => $direction]]);

    expect(opportunityLaneNames($component, $first))->toBe($firstLane)
        ->and(opportunityLaneNames($component, $second))->toBe($secondLane);
})->with([
    'newest first' => ['created_at', 'desc', ['Charlie', 'Alpha', 'Bravo'], ['Delta', 'Echo', 'Foxtrot']],
    'oldest first' => ['created_at', 'asc', ['Bravo', 'Alpha', 'Charlie'], ['Foxtrot', 'Echo', 'Delta']],
    'name a to z' => ['name', 'asc', ['Alpha', 'Bravo', 'Charlie'], ['Delta', 'Echo', 'Foxtrot']],
    'name z to a' => ['name', 'desc', ['Charlie', 'Bravo', 'Alpha'], ['Foxtrot', 'Echo', 'Delta']],
    'largest amount first' => ['custom_fields.amount', 'desc', ['Bravo', 'Alpha', 'Charlie'], ['Echo', 'Foxtrot', 'Delta']],
    'smallest amount first' => ['custom_fields.amount', 'asc', ['Charlie', 'Alpha', 'Bravo'], ['Delta', 'Foxtrot', 'Echo']],
]);

it('puts a card without a value last in a lane cell when sorting by a custom attribute', function (): void {
    [$first] = opportunitiesInCompanyLanes($this);
    opportunityInStage($this, 'Hotel', 'Prospecting', $first)->forceFill(['order_column' => 500])->saveQuietly();

    foreach (['asc', 'desc'] as $direction) {
        $component = livewire(OpportunitiesBoard::class)
            ->call('setBoardGrouping', 'company')
            ->call('setFilterBarSorts', [['column' => 'custom_fields.amount', 'direction' => $direction]]);

        expect(last(opportunityLaneNames($component, $first)))->toBe('Hotel');
    }
});

it('offers the bar a sort while lanes are shown and keeps it when lanes go away', function (): void {
    opportunitiesInCompanyLanes($this);

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'asc']])
        ->call('setBoardGrouping', 'company');

    expect($component->instance()->getFilterBarData()['sortable'])->not->toBe([])
        ->and($component->instance()->getFilterBarData()['sorts'])->toHaveCount(1)
        ->and($component->get('boardSort'))->toBe('name:asc');

    $component->call('setBoardGrouping', null);

    expect(opportunityStageNames($component, 'Prospecting'))->toBe(['Alpha', 'Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot']);
});

it('moves a card to another company lane while sorted', function (string $stage): void {
    [$first, $second] = opportunitiesInCompanyLanes($this);
    $card = Opportunity::query()->where('name', 'Bravo')->firstOrFail();
    $column = (string) $this->stageField->options->firstWhere('name', $stage)->getKey();

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardGrouping', 'company')
        ->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'asc']])
        ->call('moveCard', (string) $card->id, $column, null, null, (string) $first->id, (string) $second->id)
        ->assertDispatched('kanban-card-moved');

    expect($card->fresh()->company_id)->toBe($second->id)
        ->and(opportunityLaneNames($component, $second))->toContain('Bravo')
        ->and(opportunityLaneNames($component, $first))->not->toContain('Bravo');
})->with(['the same stage' => 'Prospecting', 'another stage' => 'Qualification']);

it('refuses a drop inside the same column while sorted', function (): void {
    opportunitiesToSort($this);
    $card = Opportunity::query()->where('name', 'Bravo')->firstOrFail();
    $stage = (string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey();
    $others = Opportunity::query()->whereKeyNot($card->getKey())->pluck('id');

    livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']])
        ->call('moveCard', (string) $card->id, $stage, (string) $others[0], (string) $others[1])
        ->assertNotified(__('filament/pages/boards.sorted_no_reorder', ['field' => 'Created at']))
        ->assertNotDispatched('kanban-card-moved');

    expect((float) $card->fresh()->order_column)->toBe(1000.0);
});

it('still reorders a column by hand when no sort is set', function (): void {
    opportunitiesToSort($this);
    $card = Opportunity::query()->where('name', 'Bravo')->firstOrFail();
    $stage = (string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey();
    $last = Opportunity::query()->where('name', 'Alpha')->firstOrFail();

    livewire(OpportunitiesBoard::class)
        ->call('moveCard', (string) $card->id, $stage, (string) $last->id, null)
        ->assertDispatched('kanban-card-moved');

    expect((float) $card->fresh()->order_column)->toBeGreaterThan(3000.0);
});

it('drops a card between two cards of another column while sorted', function (): void {
    opportunitiesToSort($this);
    $mover = opportunityInStage($this, 'Mover', 'Qualification');
    $alpha = Opportunity::query()->where('name', 'Alpha')->firstOrFail();
    $bravo = Opportunity::query()->where('name', 'Bravo')->firstOrFail();
    $prospecting = (string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey();

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']])
        ->call('moveCard', (string) $mover->id, $prospecting, (string) $alpha->id, (string) $bravo->id)
        ->assertDispatched('kanban-card-moved');

    expect((float) $mover->fresh()->order_column)->toBeLessThan(1000.0)
        ->and(opportunityStageNames($component->call('setFilterBarSorts', []), 'Prospecting'))->toBe(['Mover', 'Bravo', 'Charlie', 'Alpha']);
});

it('puts a card created while sorted at the top of the hand order', function (): void {
    opportunitiesToSort($this);
    Opportunity::query()->where('name', 'Charlie')->update(['order_column' => 200000]);
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');

    livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'desc']])
        ->call('mountAction', 'addCard', ['column' => (string) $prospecting->getKey()])
        ->set('mountedActions.0.data.name', 'Fresh deal')
        ->call('callMountedAction')
        ->assertOk();

    expect((float) Opportunity::query()->where('name', 'Fresh deal')->firstOrFail()->order_column)->toBeLessThan(1000.0);
});

it('leaves the hand order of a column untouched by moves made while sorted', function (): void {
    opportunitiesToSort($this);
    $mover = opportunityInStage($this, 'Mover', 'Qualification');
    $prospecting = (string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey();
    $before = Opportunity::query()->whereIn('name', ['Bravo', 'Charlie', 'Alpha'])->orderBy('name')->pluck('order_column', 'name')->map(fn ($value): float => (float) $value)->all();

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']])
        ->call('moveCard', (string) $mover->id, $prospecting)
        ->call('setFilterBarSorts', []);

    $after = Opportunity::query()->whereIn('name', ['Bravo', 'Charlie', 'Alpha'])->orderBy('name')->pluck('order_column', 'name')->map(fn ($value): float => (float) $value)->all();

    expect($after)->toBe($before)
        ->and(opportunityStageNames($component, 'Prospecting'))->toBe(['Mover', 'Bravo', 'Charlie', 'Alpha']);
});

it('keeps the counts right while sorted', function (string $column): void {
    opportunitiesToSort($this);
    $prospecting = (string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey();

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterRules', [['field' => 'name', 'condition' => 'contains', 'value' => 'Alp']])
        ->call('setFilterBarSorts', [['column' => $column, 'direction' => 'desc']]);

    $board = $component->instance()->getBoard();

    expect($board->getBoardRecordCount($prospecting))->toBe(1)
        ->and($board->getBatchedBoardRecordCounts()[$prospecting])->toBe(1)
        ->and(opportunityStageNames($component, 'Prospecting'))->toBe(['Alpha']);
})->with(['a native field' => 'created_at', 'a custom attribute' => 'custom_fields.amount']);

it('keeps sorting when a garbage grouping arrives after mount', function (): void {
    opportunitiesToSort($this);

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']])
        ->set('groupBy', 'stage');

    expect($component->instance()->getFilterBarData()['sortable'])->not->toBe([])
        ->and($component->instance()->getFilterBarData()['sorts'])->toHaveCount(1)
        ->and(opportunityStageNames($component, 'Prospecting'))->toBe(['Charlie', 'Alpha', 'Bravo']);
});

it('still moves a card to another stage while sorted', function (): void {
    opportunitiesToSort($this);
    $card = Opportunity::query()->where('name', 'Bravo')->firstOrFail();
    $qualification = $this->stageField->options->firstWhere('name', 'Qualification');

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']])
        ->call('moveCard', (string) $card->id, (string) $qualification->getKey())
        ->assertDispatched('kanban-card-moved');

    expect(opportunityStageNames($component, 'Qualification'))->toBe(['Bravo']);
});

it('leaves the deleted records half of the bar inert on a board', function (): void {
    $component = livewire(OpportunitiesBoard::class)->call('setDeletedRecordsFilter', 'included');

    expect($component->instance()->getFilterBarData()['deleted'])->toBeNull()
        ->and($component->instance()->hasFilterBarState())->toBeFalse();

    $fromUrl = Livewire::withQueryParams(['filters' => ['trashed' => ['value' => '1']]])->test(OpportunitiesBoard::class);

    expect($fromUrl->instance()->getFilterBarData()['deleted'])->toBeNull()
        ->and($fromUrl->instance()->hasFilterBarState())->toBeFalse();
});

it('keeps the deleted filter out of the stored filters from the first request', function (): void {
    Livewire::withQueryParams(['filters' => ['trashed' => ['value' => '1']]])->test(OpportunitiesBoard::class)
        ->assertSet('tableFilters.trashed.value', null)
        ->call('setDeletedRecordsFilter', 'included')
        ->assertSet('tableFilters.trashed.value', null);
});

it('labels every field on the board as the list does', function (): void {
    $board = collect(livewire(OpportunitiesBoard::class)->instance()->getFilterBarData()['fields'])->pluck('label', 'key');
    $list = collect(Livewire::withQueryParams(['view' => 'all'])->test(ListOpportunities::class)->instance()->getFilterBarData()['fields'])->pluck('label', 'key');

    expect($board->all())->toEqual($list->all());
});

it('draws the bar and none of the old toolbar', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();

    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml('data-filter-bar')
        ->assertDontSeeHtml('ff-grouping')
        ->assertDontSeeHtml('fi-ta-search-field')
        ->assertDontSeeHtml('fi-ta-filters-dropdown');
});

it('ignores a search that arrives in the url', function (): void {
    $card = opportunityInStage($this, 'Findable', 'Prospecting');

    $component = Livewire::withQueryParams(['search' => 'zzqx-no-match'])->test(OpportunitiesBoard::class);

    expect(opportunityStageCardIds($component, 'Prospecting'))->toContain($card->id);
});

it('renders a board whose url carries garbage filters', function (string $query): void {
    $this->get(OpportunitiesBoard::getUrl(['tenant' => $this->workspace]).'?'.$query)->assertSuccessful();
})->with([
    'trashed scalar' => 'filters[trashed]=x',
    'whole value a string' => 'filters=x',
    'conditions scalar' => 'filters[conditions]=x',
    'creation source nested junk' => 'filters[creation_source][values]=x',
]);

it('drops the removed company filter from an old link', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $card = opportunityInStage($this, 'Unrelated', 'Prospecting');

    $component = Livewire::withQueryParams(['filters' => ['companies' => ['values' => [$company->getKey()]]]])->test(OpportunitiesBoard::class);

    expect($component->instance()->getFilterBarData()['rules'])->toBe([])
        ->and(opportunityStageCardIds($component, 'Prospecting'))->toContain($card->id);
});

it('offers a company lane only for companies with an opportunity on the board', function (): void {
    $withOpportunity = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $withoutOpportunity = Company::factory()->recycle([$this->user, $this->workspace])->create();
    Opportunity::factory()->recycle([$this->user, $this->workspace])->for($withOpportunity)->create();

    $board = livewire(OpportunitiesBoard::class, ['groupBy' => 'company'])->instance()->getBoard();
    $laneNames = collect($board->getActiveGrouping()->getLanes($board->getQuery()))
        ->map(fn (Lane $lane): string => $lane->getName());

    expect($laneNames)->toContain($withOpportunity->getKey())
        ->not->toContain($withoutOpportunity->getKey());
});

it('does not show opportunities from other workspaces', function (): void {
    $otherUser = User::factory()->withWorkspace()->create();
    $otherOpportunity = Opportunity::factory()->for($otherUser->currentWorkspace)->create();

    $board = getOpportunityBoard();
    $allRecordIds = collect($this->stageField->options)
        ->flatMap(fn ($opt) => $board->getBoardRecords((string) $opt->getKey()))
        ->pluck('id');

    expect($allRecordIds)->not->toContain($otherOpportunity->id);
});

it('shows the view switcher linking list and board views', function (): void {
    livewire(ListOpportunities::class)
        ->assertSeeHtml(OpportunityResource::getUrl('board'));

    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml(OpportunityResource::getUrl('index'));
});

it('redirects the legacy board url to the resource board page', function (): void {
    $this->get(route('filament.app.opportunities-board.redirect', ['tenant' => $this->workspace->slug]))
        ->assertRedirect(OpportunityResource::getUrl('board'));
});

it('moves a card between columns via moveCard', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $qualification = $this->stageField->options->firstWhere('name', 'Qualification');

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    livewire(OpportunitiesBoard::class)
        ->call('moveCard', (string) $opportunity->id, (string) $qualification->getKey())
        ->assertDispatched('kanban-card-moved');

    $updatedValue = $opportunity->fresh()->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($updatedValue)->toBe($qualification->getKey());
});

it('moves a card between two cards of another column', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $qualification = $this->stageField->options->firstWhere('name', 'Qualification');

    [$above, $below, $opportunity] = Opportunity::factory()->count(3)->recycle([$this->user, $this->workspace])->create()->all();
    $above->saveCustomFieldValue($this->stageField, $qualification->getKey());
    $below->saveCustomFieldValue($this->stageField, $qualification->getKey());
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());
    $above->forceFill(['order_column' => 1000])->saveQuietly();
    $below->forceFill(['order_column' => 2000])->saveQuietly();

    livewire(OpportunitiesBoard::class)
        ->call('moveCard', (string) $opportunity->id, (string) $qualification->getKey(), (string) $above->id, (string) $below->id)
        ->assertDispatched('kanban-card-moved');

    $opportunity->refresh();
    $storedValue = $opportunity->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($storedValue)->toBe($qualification->getKey())
        ->and((float) $opportunity->order_column)->toBeGreaterThan(1000.0)->toBeLessThan(2000.0);
});

it('moves a card to another company lane', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    [$from, $to] = Company::factory()->count(2)->recycle([$this->user, $this->workspace])->create()->all();

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $from->id]);
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $to->id]);

    livewire(OpportunitiesBoard::class, ['groupBy' => 'company'])
        ->call('moveCard', (string) $opportunity->id, (string) $prospecting->getKey(), null, null, (string) $from->id, (string) $to->id)
        ->assertDispatched('kanban-card-moved');

    expect($opportunity->fresh()->company_id)->toBe($to->id);
});

it('moves a card into the no company lane', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id]);
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    livewire(OpportunitiesBoard::class, ['groupBy' => 'company'])
        ->call('moveCard', (string) $opportunity->id, (string) $prospecting->getKey(), null, null, (string) $company->id, Grouping::EMPTY_LANE)
        ->assertDispatched('kanban-card-moved');

    expect($opportunity->fresh()->company_id)->toBeNull();
});

it('refuses a move with a message when the user cannot update the card', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $qualification = $this->stageField->options->firstWhere('name', 'Qualification');

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(OpportunitiesBoard::class)
        ->call('moveCard', (string) $opportunity->id, (string) $qualification->getKey())
        ->assertNotified(__('filament/pages/boards.move_denied'))
        ->assertNotDispatched('kanban-card-moved');

    $storedValue = $opportunity->fresh()->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($storedValue)->toBe($prospecting->getKey());
});

it('moves a card to the stage picked from its move to sheet', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $closedWon = $this->stageField->options->firstWhere('name', 'Closed Won');

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'moveTo', [], ['recordKey' => (string) $opportunity->id])
        ->assertMountedActionModalSee(['Prospecting', 'Closed Won'])
        ->call('callMountedAction', ['to' => (string) $closedWon->getKey()])
        ->assertHasNoActionErrors()
        ->assertNotified(__('filament/pages/boards.move_to.moved', ['column' => 'Closed Won']));

    $storedValue = $opportunity->fresh()->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($storedValue)->toBe($closedWon->getKey());
});

it('keeps a card in its stage when a viewer picks another from the move to sheet', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $qualification = $this->stageField->options->firstWhere('name', 'Qualification');

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'moveTo', [], ['recordKey' => (string) $opportunity->id])
        ->call('callMountedAction', ['to' => (string) $qualification->getKey()])
        ->assertNotDispatched('kanban-card-moved');

    $storedValue = $opportunity->fresh()->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($storedValue)->toBe($prospecting->getKey());
});

it('shows a viewer no menu on a card they cannot change', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $this->stageField->options->firstWhere('name', 'Prospecting')->getKey());

    livewire(OpportunitiesBoard::class)->assertSeeHtml('ff-card-actions');

    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(OpportunitiesBoard::class)
        ->assertSee($opportunity->name)
        ->assertDontSeeHtml('ff-card-actions');
});

it('rejects a move to a stage option the board does not own', function (string $targetKind): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    User::factory()->withWorkspace()->create();
    $foreignOptionId = CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::STAGE)
        ->where('tenant_id', '!=', $this->workspace->getKey())
        ->firstOrFail()
        ->options()
        ->withoutGlobalScopes()
        ->firstOrFail()
        ->getKey();

    $targetId = $targetKind === 'foreign' ? (string) $foreignOptionId : 'not-an-option';

    livewire(OpportunitiesBoard::class)
        ->call('moveCard', (string) $opportunity->id, $targetId)
        ->assertNotified(__('flowforge::flowforge.unknown_column'))
        ->assertNotDispatched('kanban-card-moved');

    $storedValue = $opportunity->fresh()->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($storedValue)->toBe($prospecting->getKey());
})->with(['foreign workspace option' => 'foreign', 'garbage id' => 'garbage']);

it('rejects a card created in a stage option the board does not own', function (string $targetKind): void {
    User::factory()->withWorkspace()->create();
    $foreignOptionId = CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::STAGE)
        ->where('tenant_id', '!=', $this->workspace->getKey())
        ->firstOrFail()
        ->options()
        ->withoutGlobalScopes()
        ->firstOrFail()
        ->getKey();

    $targetId = $targetKind === 'foreign' ? (string) $foreignOptionId : 'not-an-option';

    livewire(OpportunitiesBoard::class)
        ->assertActionDisabled(TestAction::make('addCard')->arguments(['column' => $targetId]))
        ->call('mountAction', 'addCard', ['column' => $targetId])
        ->set('mountedActions.0.data.name', 'Rejected deal')
        ->call('callMountedAction')
        ->assertOk();

    expect(Opportunity::query()->where('name', 'Rejected deal')->exists())->toBeFalse();
})->with(['foreign workspace option' => 'foreign', 'garbage id' => 'garbage']);

it('creates a card in a stage option the board owns', function (): void {
    $qualification = $this->stageField->options->firstWhere('name', 'Qualification');

    livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'addCard', ['column' => (string) $qualification->getKey()])
        ->set('mountedActions.0.data.name', 'Accepted deal')
        ->call('callMountedAction')
        ->assertOk();

    $opportunity = Opportunity::query()->where('name', 'Accepted deal')->firstOrFail();
    $storedValue = $opportunity->customFieldValues()
        ->where('custom_field_id', $this->stageField->getKey())
        ->value($this->stageField->getValueColumn());

    expect($storedValue)->toBe($qualification->getKey());
});

it('opens the edit action when a card is clicked', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getBoard()->getCardAction())->toBe('edit');

    $component
        ->call('mountAction', 'edit', [], ['recordKey' => (string) $opportunity->id])
        ->assertSet('mountedActions.0.data.name', $opportunity->name);
});

it('opens the record preview from the button beside a card menu', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Rooftop pilot']);
    $opportunity->saveCustomFieldValue($this->stageField, $prospecting->getKey());

    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml(['fi-board-card-preview-trigger', e(PreviewRecordAction::openForRecord($opportunity))])
        ->mountAction(PreviewRecordAction::getDefaultName(), ['record' => $opportunity->getKey()])
        ->assertActionMounted(PreviewRecordAction::getDefaultName())
        ->assertMountedActionModalSeeHtml(['fi-record-embedded-page', 'Rooftop pilot']);
});

it('opens no preview for a card that is gone or belongs to another workspace', function (string $state): void {
    $outsider = User::factory()->withWorkspace()->create();
    $foreign = Opportunity::factory()->recycle([$outsider, $outsider->currentWorkspace])->create();
    $deleted = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $deleted->delete();

    $key = match ($state) {
        'foreign' => $foreign->getKey(),
        'deleted' => $deleted->getKey(),
        'unknown' => (string) Str::ulid(),
    };

    livewire(OpportunitiesBoard::class)
        ->mountAction(PreviewRecordAction::getDefaultName(), ['record' => $key])
        ->assertOk()
        ->assertSet('mountedActions', []);
})->with(['foreign', 'deleted', 'unknown']);

it('labels the contact select point of contact when adding an opportunity to a column', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');

    $page = livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'addCard', ['column' => (string) $prospecting->getKey()])
        ->instance();

    expect($page->getSchema($page->getMountedActionSchemaName())->toHtml())->toContain('Point of Contact');
});

/**
 * Sibling of the tasks board badge: "closes today" is a claim about the viewer's
 * calendar. A close date is a plain date, so the stored value is midnight UTC. Read
 * without conversion it still reads as the previous day for anyone far enough east,
 * and the pipeline card lands in the wrong urgency bucket.
 */
it('buckets the close-date badge against the user calendar, not the server clock', function (): void {
    // 23:00 UTC on the 18th is already 08:00 on the 19th in Tokyo.
    $this->travelTo(Date::parse('2026-08-18 23:00:00', 'UTC'));

    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $closeField = CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::CLOSE_DATE)
        ->first();

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $this->stageField->options->firstWhere('name', 'Prospecting')->getKey());
    $opportunity->saveCustomFieldValue($closeField, Date::parse('2026-08-19 00:00:00', 'UTC'));

    livewire(OpportunitiesBoard::class)
        ->assertSee('Closes Today')
        ->assertDontSee('Closes Tomorrow');
});

/**
 * The mirror of the case above, for a viewer west of UTC. A close date is a plain
 * calendar date that the package stores at midnight UTC, so converting it into a
 * negative-offset zone walks it back past midnight and the card reads a day early.
 * the failure the eastern case cannot see, because moving midnight forward stays
 * inside the same day.
 */
it('does not walk a close date back a day for a viewer west of utc', function (): void {
    // 16:00 UTC on the 19th is 09:00 the same morning in Los Angeles.
    $this->travelTo(Date::parse('2026-08-19 16:00:00', 'UTC'));

    $this->user->forceFill(['timezone' => 'America/Los_Angeles'])->save();
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $closeField = CustomField::query()
        ->forEntity(Opportunity::class)
        ->where('code', OpportunityField::CLOSE_DATE)
        ->first();

    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();
    $opportunity->saveCustomFieldValue($this->stageField, $this->stageField->options->firstWhere('name', 'Prospecting')->getKey());
    $opportunity->saveCustomFieldValue($closeField, Date::parse('2026-08-19 00:00:00', 'UTC'));

    livewire(OpportunitiesBoard::class)
        ->assertSee('Closes Today')
        ->assertDontSee('Overdue');
});

it('lists the stages in the order the field settings give them by default', function (): void {
    expect(boardColumnOrder(freshOpportunitiesBoard()))->toBe(stageIds($this->stageField));
});

it('keeps a reordered stage list for the member on the same page and on a new visit', function (): void {
    $reversed = array_reverse(stageIds($this->stageField));

    $component = livewire(OpportunitiesBoard::class)->call('reorderBoardStages', $reversed);

    expect(boardColumnOrder($component))->toBe($reversed)
        ->and(boardColumnOrder(freshOpportunitiesBoard()))->toBe($reversed);
});

it('leaves another member\'s board in the field order', function (): void {
    $other = User::factory()->create();
    $this->workspace->users()->attach($other, ['role' => WorkspaceRole::Member->value]);
    $other->switchWorkspace($this->workspace);

    livewire(OpportunitiesBoard::class)->call('reorderBoardStages', array_reverse(stageIds($this->stageField)));

    $this->actingAs($other);

    expect(boardColumnOrder(freshOpportunitiesBoard()))->toBe(stageIds($this->stageField));
});

it('never writes the field options when stages are reordered', function (): void {
    $before = CustomFieldOption::query()->where('custom_field_id', $this->stageField->getKey())->pluck('sort_order', 'id')->all();
    $fieldBefore = $this->stageField->fresh()->only(['sort_order', 'settings']);

    livewire(OpportunitiesBoard::class)->call('reorderBoardStages', array_reverse(stageIds($this->stageField)));

    expect(CustomFieldOption::query()->where('custom_field_id', $this->stageField->getKey())->pluck('sort_order', 'id')->all())->toBe($before)
        ->and($this->stageField->fresh()->only(['sort_order', 'settings']))->toEqual($fieldBefore);
});

it('puts a stage added after the reorder at the end and drops one that was deleted', function (): void {
    $order = stageIds($this->stageField);
    $reversed = array_reverse($order);

    livewire(OpportunitiesBoard::class)->call('reorderBoardStages', $reversed);

    $added = CustomFieldOption::query()->create([
        'custom_field_id' => $this->stageField->getKey(),
        'tenant_id' => $this->workspace->getKey(),
        'name' => 'Negotiation round two',
        'sort_order' => 99,
    ]);
    CustomFieldOption::query()->whereKey($reversed[0])->delete();

    expect(boardColumnOrder(freshOpportunitiesBoard()))->toBe([...array_slice($reversed, 1), (string) $added->getKey()]);
});

it('keeps the member\'s stored filters and lanes when stages are reordered', function (): void {
    $filters = ['conditions' => ['match' => 'all', 'rules' => [['field' => 'name', 'condition' => 'contains', 'value' => 'Acme']]], 'trashed' => ['value' => null]];
    TablePreference::factory()->create([
        'user_id' => $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'entity' => CrmEntity::Opportunity,
        'board_state' => new BoardStateData(filters: $filters, grouping: 'company'),
    ]);
    $reversed = array_reverse(stageIds($this->stageField));

    livewire(OpportunitiesBoard::class)->call('reorderBoardStages', $reversed);

    $stored = TablePreference::query()->sole()->board_state;
    expect($stored->stageOrder)->toBe($reversed)
        ->and($stored->filters)->toEqual($filters)
        ->and($stored->grouping)->toBe('company');
});

it('keeps every board setting when stages are reordered', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $tierIds = $tier->options->pluck('id')->map(strval(...))->all();
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(stageOrder: array_reverse($tierIds), hiddenColumns: [$tierIds[0]], noValueColumns: ['stage'], cardAttributes: ['company'], showLabels: true, hideEmpty: false, collapsedColumns: [$tierIds[1]], columnsCollapsed: true, cardSize: BoardCardSize::Compact));
    $reversed = array_reverse(stageIds($this->stageField));

    livewire(OpportunitiesBoard::class)->call('reorderBoardStages', $reversed);

    $stored = TablePreference::query()->sole()->board_state;
    expect($stored->orderStages($tierIds))->toBe(array_reverse($tierIds))
        ->and($stored->orderStages(stageIds($this->stageField)))->toBe($reversed)
        ->and($stored->hiddenColumns)->toBe([$tierIds[0]])
        ->and($stored->noValueColumns)->toBe(['stage'])
        ->and($stored->cardAttributes)->toBe(['company'])
        ->and($stored->showLabels)->toBeTrue()
        ->and($stored->hideEmpty)->toBeFalse()
        ->and($stored->collapsedColumns)->toBe([$tierIds[1]])
        ->and($stored->columnsCollapsed)->toBeTrue()
        ->and($stored->cardSize)->toBe(BoardCardSize::Compact);
});

it('keeps every board setting of an open board view when stages are reordered', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $tierIds = $tier->options->pluck('id')->map(strval(...))->all();
    $plain = rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(sort: 'name:asc'));
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'type' => SavedViewType::Board,
        'state' => new BoardStateData(stageOrder: array_reverse($tierIds), hiddenColumns: [$tierIds[0]]),
    ]);
    $plainHash = $plain->board_state->hash();
    $reversed = array_reverse(stageIds($this->stageField));

    Livewire::withQueryParams(['view' => $view->getKey()])->test(OpportunitiesBoard::class)->call('reorderBoardStages', $reversed);

    $stored = $view->refresh()->boardState();
    expect($stored->orderStages($tierIds))->toBe(array_reverse($tierIds))
        ->and($stored->orderStages(stageIds($this->stageField)))->toBe($reversed)
        ->and($stored->hiddenColumns)->toBe([$tierIds[0]])
        ->and($plain->refresh()->board_state->hash())->toBe($plainHash);
});

it('stores a sort change that arrives in the same request as a stage reorder', function (): void {
    $reversed = array_reverse(stageIds($this->stageField));

    livewire(OpportunitiesBoard::class)->update(
        calls: [['path' => '', 'method' => 'reorderBoardStages', 'params' => [$reversed]]],
        updates: ['boardSort' => 'name:desc'],
    );

    $stored = TablePreference::query()->sole()->board_state;
    expect($stored->sort)->toBe('name:desc')
        ->and($stored->stageOrder)->toBe($reversed);
});

it('writes nothing on arrival when Part 2 settings are stored', function (): void {
    $preference = rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: ['company'], showLabels: true, columnsCollapsed: true));
    $updatedAt = $preference->updated_at;
    $this->travelTo(now()->addHour());

    $writes = boardPreferenceWrites(fn () => freshOpportunitiesBoard()->call('$refresh'));

    expect($writes)->toBe(0)
        ->and($preference->refresh()->updated_at)->toEqual($updatedAt);
});

it('stores an order made of the board\'s own stages once each', function (): void {
    $order = stageIds($this->stageField);

    $state = resolve(ReorderBoardStages::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, [$order[2], $order[0], $order[2]]);

    expect($state->stageOrder)->toBe([$order[2], $order[1], $order[0], ...array_slice($order, 3)])
        ->and(TablePreference::query()->count())->toBe(1);
});

it('rejects a stage order the member may not store', function (string $case): void {
    $foreign = User::factory()->withWorkspace()->create();
    $foreignField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $foreign->currentWorkspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', OpportunityField::STAGE)
        ->firstOrFail();
    $taskStatus = CustomField::query()->forEntity(Task::class)->where('code', 'status')->firstOrFail();
    $outsider = User::factory()->create();

    [$user, $ids] = match ($case) {
        'another workspace' => [$this->user, [stageIds($this->stageField)[0], ...stageIds($foreignField)]],
        'another field' => [$this->user, [...stageIds($this->stageField), ...stageIds($taskStatus)]],
        'unknown id' => [$this->user, [stageIds($this->stageField)[0], 'not-an-option']],
        'non member' => [$outsider, stageIds($this->stageField)],
    };

    expect(fn () => resolve(ReorderBoardStages::class)->execute($user, $this->workspace, CrmEntity::Opportunity, $ids))
        ->toThrow(HttpException::class);

    expect(TablePreference::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['another workspace', 'another field', 'unknown id', 'non member']);

it('refuses a stage order for an entity that has no board', function (): void {
    expect(fn () => resolve(ReorderBoardStages::class)->execute($this->user, $this->workspace, CrmEntity::Company, ['a']))
        ->toThrow(HttpException::class);
});

it('refuses a stage order while the stage field is inactive', function (): void {
    $this->stageField->forceFill(['active' => false])->save();

    expect(fn () => resolve(ReorderBoardStages::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, stageIds($this->stageField)))
        ->toThrow(HttpException::class);
});

it('lists the stages in the field order for a member whose stored board state is empty', function (): void {
    $preference = TablePreference::factory()->create([
        'user_id' => $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'entity' => CrmEntity::Opportunity,
    ]);
    DB::table('table_preferences')->where('id', $preference->getKey())->update(['board_state' => null]);

    expect(boardColumnOrder(freshOpportunitiesBoard()))->toBe(stageIds($this->stageField));
});

function boardFiltersWithRule(array $rule): array
{
    return ['conditions' => ['match' => 'all', 'rules' => [$rule]], 'trashed' => ['value' => null]];
}

function boardPreferenceWrites(Closure $callback): int
{
    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^(update|insert into) "table_preferences"/', $query->sql) === 1) {
            $writes++;
        }
    });
    $callback();

    return $writes;
}

it('remembers a filter and the lanes set on the plain board for the next visit', function (): void {
    opportunitiesToSort($this);
    $rule = ['field' => 'name', 'condition' => 'contains', 'value' => 'a'];

    livewire(OpportunitiesBoard::class)
        ->call('setFilterRules', [$rule])
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']])
        ->call('setBoardGrouping', 'company');

    $next = freshOpportunitiesBoard();

    expect($next->get('groupBy'))->toBe('company')
        ->and($next->get('boardSort'))->toBe('created_at:desc')
        ->and($next->instance()->getFilterBarData()['rules'])->toHaveCount(1)
        ->and(TablePreference::query()->sole()->board_state->grouping)->toBe('company');
});

it('narrows the first render with the remembered filter', function (): void {
    $keep = opportunityInStage($this, 'Keep me', 'Prospecting');
    $drop = opportunityInStage($this, 'Drop me', 'Prospecting');
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(filters: boardFiltersWithRule(['field' => 'name', 'condition' => 'contains', 'value' => 'Keep'])));

    $first = freshOpportunitiesBoard();

    $first->assertSee('Keep me')->assertDontSee('Drop me');

    expect(opportunityStageCardIds($first, 'Prospecting'))->toBe([$keep->id])
        ->and($drop->id)->not->toBeIn(opportunityStageCardIds($first, 'Prospecting'));
});

it('lets a filter in the url win for that visit and writes nothing on arrival', function (): void {
    $remembered = new BoardStateData(filters: boardFiltersWithRule(['field' => 'name', 'condition' => 'contains', 'value' => 'A']));
    $preference = rememberedBoard($this, CrmEntity::Opportunity, $remembered);
    $updatedAt = $preference->updated_at;
    $urlRule = ['field' => 'name', 'condition' => 'contains', 'value' => 'B'];

    $this->travelTo(now()->addHour());
    $component = Livewire::withQueryParams(['filters' => ['conditions' => ['match' => 'all', 'rules' => [$urlRule]]]])->test(OpportunitiesBoard::class);
    $component->call('$refresh');

    expect($component->instance()->getFilterBarData()['rules'][0]['value'])->toBe('B')
        ->and($preference->refresh()->updated_at)->toEqual($updatedAt)
        ->and($preference->board_state->filters['conditions']['rules'][0]['value'])->toBe('A');
});

it('remembers the url filter once the member changes something', function (): void {
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(filters: boardFiltersWithRule(['field' => 'name', 'condition' => 'contains', 'value' => 'A'])));
    $urlRule = ['field' => 'name', 'condition' => 'contains', 'value' => 'B'];

    Livewire::withQueryParams(['filters' => ['conditions' => ['match' => 'all', 'rules' => [$urlRule]]]])->test(OpportunitiesBoard::class)
        ->call('setBoardGrouping', 'company');

    $stored = TablePreference::query()->sole()->board_state;
    expect($stored->filters['conditions']['rules'][0]['value'])->toBe('B')
        ->and($stored->grouping)->toBe('company');
});

it('writes nothing for a request that changes nothing', function (): void {
    $preference = rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(grouping: 'company'));
    $updatedAt = $preference->updated_at;
    $component = freshOpportunitiesBoard();

    $this->travelTo(now()->addHour());
    $writes = boardPreferenceWrites(function () use ($component): void {
        $component->call('$refresh')->call('setBoardGrouping', 'company')->call('setFilterBarSorts', []);
    });

    expect($writes)->toBe(0)
        ->and($preference->refresh()->updated_at)->toEqual($updatedAt);
});

it('writes once when stages are reordered', function (): void {
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(grouping: 'company'));
    $component = freshOpportunitiesBoard();

    $writes = boardPreferenceWrites(fn () => $component->call('reorderBoardStages', array_reverse(stageIds($this->stageField))));

    expect($writes)->toBe(1);
});

it('keeps each member\'s remembered board apart', function (): void {
    $other = User::factory()->create();
    $this->workspace->users()->attach($other, ['role' => WorkspaceRole::Member->value]);
    $other->switchWorkspace($this->workspace);

    livewire(OpportunitiesBoard::class)->call('setBoardGrouping', 'company');

    $this->actingAs($other);

    expect(freshOpportunitiesBoard()->get('groupBy'))->toBeNull();
});

it('renders a board whose remembered filter names a deleted custom attribute or option', function (): void {
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(filters: [
        'conditions' => ['match' => 'all', 'rules' => [
            ['field' => 'custom_fields.gone', 'condition' => 'is_not_empty'],
            ['field' => 'custom_fields.stage', 'condition' => 'is_any_of', 'value' => ['01jgone0000000000000000000']],
        ]],
        'trashed' => ['value' => null],
    ]));

    freshOpportunitiesBoard()->assertOk();

    expect(freshOpportunitiesBoard()->instance()->getFilterBarData()['rules'])->toBe([]);
});

it('drops a grouping the board does not have, from the url and from the stored state', function (): void {
    Livewire::withQueryParams(['groupBy' => 'stage'])->test(OpportunitiesBoard::class)->assertSet('groupBy', null);

    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(grouping: 'stage'));

    freshOpportunitiesBoard()->assertSet('groupBy', null);
});

it('keeps the filter when a sort is set', function (): void {
    opportunitiesToSort($this);
    $rule = ['field' => 'name', 'condition' => 'contains', 'value' => 'a'];

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterRules', [$rule])
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']]);

    expect($component->instance()->getFilterBarData()['rules'])->toHaveCount(1)
        ->and(opportunityStageNames($component, 'Prospecting'))->toBe(['Charlie', 'Alpha', 'Bravo']);

    $component->call('setFilterRules', [['field' => 'name', 'condition' => 'contains', 'value' => 'Alp']])
        ->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'asc']]);

    expect(opportunityStageNames($component, 'Prospecting'))->toBe(['Alpha']);
});

it('keeps the filter when lanes are switched on and off', function (): void {
    $keep = opportunityInStage($this, 'Keep me', 'Prospecting');
    opportunityInStage($this, 'Drop me', 'Prospecting');

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterRules', [['field' => 'name', 'condition' => 'contains', 'value' => 'Keep']]);

    foreach (['company', null] as $grouping) {
        $component->call('setBoardGrouping', $grouping);

        expect($component->instance()->getFilterBarData()['rules'])->toHaveCount(1);
    }

    expect(opportunityStageCardIds($component, 'Prospecting'))->toBe([$keep->id]);
});

it('keeps stored keys the page does not own when a filter changes', function (): void {
    $order = array_reverse(stageIds($this->stageField));
    $filters = boardFiltersWithRule(['field' => 'name', 'condition' => 'contains', 'value' => 'Acme']);
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(stageOrder: $order, filters: $filters));
    $component = freshOpportunitiesBoard();

    $writes = boardPreferenceWrites(fn () => $component->call('setBoardGrouping', 'company'));

    $stored = TablePreference::query()->sole()->board_state;
    expect($writes)->toBe(1)
        ->and($stored->stageOrder)->toBe($order)
        ->and($stored->filters)->toEqual($filters)
        ->and($stored->grouping)->toBe('company');
});

it('opens the remembered board when the url state normalises to nothing', function (array $query): void {
    $filters = boardFiltersWithRule(['field' => 'name', 'condition' => 'contains', 'value' => 'Keep']);
    $preference = rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(filters: $filters, grouping: 'company', sort: 'created_at:desc'));
    $updatedAt = $preference->updated_at;

    $this->travelTo(now()->addHour());
    $component = Livewire::withQueryParams($query)->test(OpportunitiesBoard::class);

    expect($component->get('groupBy'))->toBe('company')
        ->and($component->get('boardSort'))->toBe('created_at:desc')
        ->and($component->instance()->getFilterBarData()['rules'])->toHaveCount(1)
        ->and($preference->refresh()->updated_at)->toEqual($updatedAt);

    $component->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'asc']]);

    $stored = TablePreference::query()->sole()->board_state;
    expect($stored->filters)->toEqual($filters)
        ->and($stored->grouping)->toBe('company')
        ->and($stored->sort)->toBe('name:asc');
})->with([
    'an unknown grouping' => [['groupBy' => 'stage']],
    'a legacy filter key' => [['filters' => ['companies' => ['values' => ['x']]]]],
    'an empty rule list' => [['filters' => ['conditions' => ['rules' => '']]]],
    'a garbage sort' => [['sort' => 'garbage']],
]);

it('stores no sort and no lanes the board does not offer', function (): void {
    $rule = ['field' => 'name', 'condition' => 'contains', 'value' => 'Acme'];
    $component = Livewire::withQueryParams([
        'sort' => 'x;drop',
        'groupBy' => 'stage',
        'filters' => ['conditions' => ['rules' => [$rule]]],
    ])->test(OpportunitiesBoard::class);

    $component->call('setFilterRules', [[...$rule, 'value' => 'Other']]);

    $stored = TablePreference::query()->sole()->board_state;
    expect($stored->sort)->toBeNull()
        ->and($stored->grouping)->toBeNull()
        ->and($component->get('boardSort'))->toBeNull()
        ->and($component->get('groupBy'))->toBeNull();
});

it('keeps the stored sort while lanes are shown', function (): void {
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(sort: 'created_at:desc'));

    $component = freshOpportunitiesBoard()->call('setBoardGrouping', 'company');

    $stored = TablePreference::query()->sole()->board_state;
    expect($component->instance()->getFilterBarData()['sorts'])->toHaveCount(1)
        ->and($stored->sort)->toBe('created_at:desc')
        ->and($stored->grouping)->toBe('company');
});

it('writes nothing while the board mounts for the first time', function (bool $stored): void {
    if ($stored) {
        rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(grouping: 'company'));
    }

    $writes = boardPreferenceWrites(fn () => freshOpportunitiesBoard());

    expect($writes)->toBe(0)
        ->and(TablePreference::query()->count())->toBe($stored ? 1 : 0);
})->with(['a member with no row' => false, 'a member with a stored row' => true]);

it('narrows the first render with the open board view\'s filter and keeps its own stage order', function (): void {
    $keep = opportunityInStage($this, 'Keep me', 'Prospecting');
    opportunityInStage($this, 'Drop me', 'Prospecting');
    $reversed = array_reverse(stageIds($this->stageField));
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'type' => SavedViewType::Board,
        'state' => new BoardStateData(stageOrder: $reversed, filters: boardFiltersWithRule(['field' => 'name', 'condition' => 'contains', 'value' => 'Keep'])),
    ]);

    $component = Livewire::withQueryParams(['view' => $view->getKey()])->test(OpportunitiesBoard::class);

    $component->assertSee('Keep me')->assertDontSee('Drop me');

    expect(opportunityStageCardIds($component, 'Prospecting'))->toBe([$keep->id])
        ->and(boardColumnOrder($component))->toBe($reversed)
        ->and(TablePreference::query()->count())->toBe(0);
});

it('collapses a stage for the member and expands it again', function (): void {
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([]);

    $component->call('setBoardColumnCollapsed', $ids[1], true);

    expect(TablePreference::query()->sole()->board_state->collapsedColumns)->toBe([$ids[1]])
        ->and($component->instance()->getBoard()->getCollapsedColumns())->toBe([$ids[1]])
        ->and($component->html())->toContain('data-collapsed');

    $component->call('setBoardColumnCollapsed', $ids[1], false);

    expect(TablePreference::query()->sole()->board_state->collapsedColumns)->toBe([])
        ->and($component->instance()->getBoard()->getCollapsedColumns())->toBe([]);
});

it('opens one stage out of collapse all and keeps the others collapsed', function (): void {
    $ids = stageIds($this->stageField);

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnsCollapsed', true)
        ->call('toggleBoardColumnCollapse', $ids[2]);

    $state = TablePreference::query()->sole()->board_state;

    expect($state->columnsCollapsed)->toBeFalse()
        ->and($state->collapsedColumns)->toEqualCanonicalizing(array_values(array_diff($ids, [$ids[2]])))
        ->and($state->collapsedNoValueColumns)->toBe([$this->stageField->code]);
});

it('toggles a stage through the rail collapse callback', function (): void {
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class)->call('toggleBoardColumnCollapse', $ids[2]);

    expect(TablePreference::query()->sole()->board_state->collapsedColumns)->toBe([$ids[2]]);

    $component->call('toggleBoardColumnCollapse', $ids[2]);

    expect(TablePreference::query()->sole()->board_state->collapsedColumns)->toBe([]);
});

it('collapses the no-value column', function (): void {
    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnCollapsed', BoardStateData::NO_VALUE_COLUMN, true);

    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([BoardStateData::NO_VALUE_COLUMN]);
});

it('keeps another member\'s board expanded', function (): void {
    $ids = stageIds($this->stageField);
    livewire(OpportunitiesBoard::class)->call('setBoardColumnCollapsed', $ids[1], true);

    $colleague = User::factory()->create();
    $this->workspace->users()->attach($colleague, ['role' => WorkspaceRole::Member->value]);
    $colleague->switchWorkspace($this->workspace);
    $this->actingAs($colleague);

    expect(livewire(OpportunitiesBoard::class)->instance()->getBoard()->getCollapsedColumns())->toBe([]);
});

it('keeps a collapsed stage across regrouping by another attribute', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnCollapsed', $ids[1], true);

    $component->call('setBoardColumnField', $tier->code);
    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([]);

    $component->call('setBoardColumnField', $this->stageField->code);
    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([$ids[1]])
        ->and(TablePreference::query()->sole()->board_state->collapsedColumns)->toBe([$ids[1]]);
});

it('stores collapsed stages inside an open board view only', function (): void {
    $ids = stageIds($this->stageField);
    $plain = rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(sort: 'name:asc'));
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'type' => SavedViewType::Board,
        'state' => new BoardStateData,
    ]);
    $plainHash = $plain->board_state->hash();

    $component = Livewire::withQueryParams(['view' => $view->getKey()])->test(OpportunitiesBoard::class)
        ->call('setBoardColumnCollapsed', $ids[0], true);

    expect($view->refresh()->boardState()->collapsedColumns)->toBe([$ids[0]])
        ->and($component->instance()->getBoard()->getCollapsedColumns())->toBe([$ids[0]])
        ->and($plain->refresh()->board_state->hash())->toBe($plainHash);
});

it('collapses every column from the bar and keeps it after a reload', function (): void {
    livewire(OpportunitiesBoard::class)->call('setBoardColumnsCollapsed', true);

    expect(TablePreference::query()->sole()->board_state->columnsCollapsed)->toBeTrue()
        ->and(freshOpportunitiesBoard()->instance()->getBoard()->collapsesAllColumns())->toBeTrue();

    $component = freshOpportunitiesBoard()->call('setBoardColumnsCollapsed', false);

    expect($component->instance()->getBoard()->collapsesAllColumns())->toBeFalse()
        ->and(TablePreference::query()->sole()->board_state->columnsCollapsed)->toBeFalse();
});

it('renders a collapsed stage that was then deleted or hidden without error', function (string $case): void {
    $ids = stageIds($this->stageField);
    livewire(OpportunitiesBoard::class)->call('setBoardColumnCollapsed', $ids[1], true);

    if ($case === 'deleted') {
        CustomFieldOption::query()->withoutGlobalScopes()->whereKey($ids[1])->delete();
    } else {
        livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', $ids[1], false);
    }

    $component = freshOpportunitiesBoard()->assertOk();

    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([])
        ->and(boardColumnOrder($component))->not->toContain($ids[1]);

    $component->call('setBoardColumnCollapsed', $ids[0], true)->assertOk();

    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([$ids[0]]);
})->with(['deleted', 'hidden']);

it('ignores a collapse call for a stage the board does not own', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold']);

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnCollapsed', (string) $tier->options->first()->getKey(), true)
        ->call('setBoardColumnCollapsed', 'nope', true)
        ->call('setBoardColumnCollapsed', null, true)
        ->call('setBoardColumnCollapsed', [1], true);

    expect(TablePreference::query()->count())->toBe(0);
});

it('refuses a collapse of an option of another attribute or workspace', function (string $case): void {
    $foreign = User::factory()->withWorkspace()->create();
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $foreignStage = CustomField::query()->withoutGlobalScopes()->where('tenant_id', $foreign->currentWorkspace->getKey())->where('entity_type', 'opportunity')->where('code', OpportunityField::STAGE)->firstOrFail();

    $optionId = match ($case) {
        'another attribute' => (string) $tier->options->first()->getKey(),
        'another workspace' => stageIds($foreignStage)[0],
        'unknown' => 'not-an-option',
    };

    expect(fn () => resolve(SetBoardColumnCollapsed::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, OpportunityField::STAGE->value, $optionId, true))
        ->toThrow(HttpException::class);

    expect(TablePreference::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['another attribute', 'another workspace', 'unknown']);

it('keeps the collapsed stages of other attributes when one attribute collapses', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $tierId = (string) $tier->options->first()->getKey();
    $stageId = stageIds($this->stageField)[0];
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(collapsedColumns: [$tierId]));

    $state = resolve(SetBoardColumnCollapsed::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, OpportunityField::STAGE->value, $stageId, true);

    expect($state->collapsedColumns)->toEqualCanonicalizing([$tierId, $stageId]);
});

it('reads a stored board state that still carries the retired column width', function (): void {
    $preference = rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData);
    DB::table('table_preferences')->where('id', $preference->getKey())->update(['board_state' => json_encode(['column_width' => 'narrow', 'card_size' => 'compact'])]);

    $component = freshOpportunitiesBoard()->assertOk();

    expect($component->instance()->getBoard()->collapsesAllColumns())->toBeFalse()
        ->and($preference->refresh()->board_state->cardSize)->toBe(BoardCardSize::Compact);
});

it('keeps the no-value collapse with the attribute it was set on', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnCollapsed', BoardStateData::NO_VALUE_COLUMN, true);

    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([BoardStateData::NO_VALUE_COLUMN]);

    $component->call('setBoardColumnField', $tier->code)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true);

    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([]);

    $component->call('setBoardColumnField', $this->stageField->code);

    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([BoardStateData::NO_VALUE_COLUMN]);
});

it('opens a new attribute with its no-value column expanded', function (): void {
    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnCollapsed', BoardStateData::NO_VALUE_COLUMN, true);

    $component = livewire(OpportunitiesBoard::class)
        ->callAction('createColumnAttribute', data: ['name' => 'Lead tier', 'options' => [['name' => 'Hot']]]);

    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([]);
});

function stageOption(string $id): CustomFieldOption
{
    return CustomFieldOption::query()->withoutGlobalScopes()->findOrFail($id);
}

function stageMenuOf(Testable $component, string $id): string
{
    $dom = new DOMDocument;
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>'.$component->html());
    $node = (new DOMXPath($dom))->query('//*[@data-stage-menu="'.$id.'"]')->item(0);

    return $node === null ? '' : (string) $dom->saveHTML($node);
}

function makeBoardMember(object $test, WorkspaceRole $role = WorkspaceRole::Member): User
{
    $member = User::factory()->create();
    $test->workspace->users()->attach($member, ['role' => $role->value]);
    $member->switchWorkspace($test->workspace);

    return $member;
}

it('renames a stage from its header menu, trims the name and logs the change', function (): void {
    $id = stageIds($this->stageField)[1];
    $component = livewire(OpportunitiesBoard::class)
        ->call('renameBoardColumn', $id, '  Discovery  ')
        ->assertHasNoErrors();

    expect(stageOption($id)->name)->toBe('Discovery')
        ->and($component->instance()->getBoard()->getColumn($id)->getLabel())->toBe('Discovery')
        ->and(Activity::withoutGlobalScopes()->where('subject_id', $id)->where('event', 'updated')->exists())->toBeTrue();
});

it('keeps the option order and the other stages when one is renamed', function (): void {
    $ids = stageIds($this->stageField);
    $names = CustomFieldOption::query()->withoutGlobalScopes()->whereIn('id', $ids)->pluck('name', 'id');

    livewire(OpportunitiesBoard::class)->call('renameBoardColumn', $ids[2], 'Discovery');

    $after = CustomFieldOption::query()->withoutGlobalScopes()->whereIn('id', $ids)->orderBy('sort_order')->pluck('name', 'id');

    expect($after->keys()->all())->toBe($ids)
        ->and($after->except($ids[2])->all())->toBe($names->except($ids[2])->all());
});

it('shows an error in the menu and changes nothing for a blank or taken stage name', function (string $name): void {
    $ids = stageIds($this->stageField);
    $before = stageOption($ids[1])->name;
    $taken = match ($name) {
        'taken' => stageOption($ids[0])->name,
        'taken-upper' => strtoupper(stageOption($ids[0])->name),
        default => $name,
    };

    $component = livewire(OpportunitiesBoard::class)->call('renameBoardColumn', $ids[1], $taken);

    $component->assertHasErrors(['boardStageMenu.'.$ids[1]]);

    expect(stageOption($ids[1])->name)->toBe($before)
        ->and(stageMenuOf($component, $ids[1]))->toContain('data-stage-error');
})->with(['blank' => '   ', 'taken' => 'taken', 'taken in another case' => 'taken-upper']);

it('words a taken name in a rename the same way as in an add', function (): void {
    $ids = stageIds($this->stageField);
    $taken = strtoupper(stageOption($ids[0])->name);

    $component = livewire(OpportunitiesBoard::class)->call('renameBoardColumn', $ids[1], $taken);

    expect($component->errors()->get('boardStageMenu.'.$ids[1]))->toBe([__('filament/pages/boards.stage_menu.name_taken', ['noun' => 'stage', 'name' => $taken])]);
});

it('clears the menu error once a rename succeeds', function (): void {
    $id = stageIds($this->stageField)[1];

    livewire(OpportunitiesBoard::class)
        ->call('renameBoardColumn', $id, '')
        ->assertHasErrors(['boardStageMenu.'.$id])
        ->call('renameBoardColumn', $id, 'Discovery')
        ->assertHasNoErrors();
});

it('ignores a rename of the same name and of an id the board does not own', function (): void {
    $id = stageIds($this->stageField)[1];
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $tierId = (string) $tier->options->first()->getKey();
    $name = stageOption($id)->name;

    livewire(OpportunitiesBoard::class)
        ->call('renameBoardColumn', $id, $name)
        ->call('renameBoardColumn', $tierId, 'Platinum')
        ->call('renameBoardColumn', BoardStateData::NO_VALUE_COLUMN, 'Nothing')
        ->assertHasNoErrors();

    expect(stageOption($id)->name)->toBe($name)
        ->and(stageOption($tierId)->name)->toBe('Gold');
});

it('sets a stage color from the palette and turns option colors on', function (): void {
    $id = stageIds($this->stageField)[1];
    $settings = $this->stageField->settings;
    $settings->enable_option_colors = false;
    $this->stageField->update(['settings' => $settings]);

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnColor', $id, OptionColor::Blue->value)
        ->assertHasNoErrors();

    expect(stageOption($id)->settings->color)->toBe(OptionColor::Blue->value)
        ->and($this->stageField->refresh()->settings->enable_option_colors)->toBeTrue();
});

it('rings the current stage color in the swatch grid', function (): void {
    $id = stageIds($this->stageField)[1];

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnColor', $id, OptionColor::Pink->value);

    $pink = OptionColor::Pink->value;

    expect(stageMenuOf($component, $id))->toMatch("/data-stage-swatch=\"{$pink}\"[^>]*aria-pressed=\"true\"|aria-pressed=\"true\"[^>]*data-stage-swatch=\"{$pink}\"/");
});

it('refuses a color outside the palette and changes nothing', function (): void {
    $id = stageIds($this->stageField)[1];
    $before = stageOption($id)->settings->color;

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnColor', $id, '#123456')
        ->assertHasErrors(['boardStageMenu.'.$id]);

    expect(stageOption($id)->settings->color)->toBe($before);
});

it('refuses a forged rename or color from a member and from a viewer', function (WorkspaceRole $role): void {
    $id = stageIds($this->stageField)[1];
    $name = stageOption($id)->name;
    $color = stageOption($id)->settings->color;
    $this->actingAs(makeBoardMember($this, $role));

    livewire(OpportunitiesBoard::class)->call('renameBoardColumn', $id, 'Forged')->assertForbidden();
    livewire(OpportunitiesBoard::class)->call('setBoardColumnColor', $id, OptionColor::Red->value)->assertForbidden();

    expect(stageOption($id)->name)->toBe($name)
        ->and(stageOption($id)->settings->color)->toBe($color);
})->with([WorkspaceRole::Member, WorkspaceRole::Viewer]);

it('renders the edit block for an admin and plain text with collapse and hide for a member', function (): void {
    $id = stageIds($this->stageField)[1];

    $admin = livewire(OpportunitiesBoard::class);

    expect(stageMenuOf($admin, $id))->toContain('data-stage-edit="'.$id.'"')->toContain('data-stage-name-input')->toContain('data-stage-swatch');

    $this->actingAs(makeBoardMember($this));
    $member = livewire(OpportunitiesBoard::class);

    expect(stageMenuOf($member, $id))->toContain(stageOption($id)->name)->not->toContain('data-stage-edit')->not->toContain('data-stage-name-input')->not->toContain('data-stage-swatch')
        ->and($member->html())->toContain('Collapse stage')->toContain('Hide stage');
});

it('gives the no-value column collapse and hide but no edit block', function (): void {
    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true);

    expect(stageMenuOf($component, BoardStateData::NO_VALUE_COLUMN))->toContain('data-stage-menu')->not->toContain('data-stage-edit')->not->toContain('data-stage-name-input')->not->toContain('data-stage-swatch')
        ->and($component->html())->toContain('Collapse stage');

    $component->callAction(TestAction::make('collapseStage')->arguments(['column' => BoardStateData::NO_VALUE_COLUMN]));

    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([BoardStateData::NO_VALUE_COLUMN]);
});

it('collapses and hides a stage from the menu actions', function (): void {
    $ids = stageIds($this->stageField);

    $component = livewire(OpportunitiesBoard::class)
        ->callAction(TestAction::make('collapseStage')->arguments(['column' => $ids[1]]))
        ->callAction(TestAction::make('hideStage')->arguments(['column' => $ids[2]]));

    expect($component->instance()->getBoard()->getCollapsedColumns())->toBe([$ids[1]])
        ->and(TablePreference::query()->sole()->board_state->hiddenColumns)->toBe([$ids[2]]);
});

it('refuses to hide the last visible stage with a notification', function (): void {
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class);

    foreach (array_slice($ids, 1) as $id) {
        $component->call('setBoardColumnVisible', $id, false);
    }

    $component->callAction(TestAction::make('hideStage')->arguments(['column' => $ids[0]]))
        ->assertNotified(__('filament/pages/boards.stage_menu.last_visible', ['noun' => 'stage']));

    expect(TablePreference::query()->sole()->board_state->hiddenColumns)->not->toContain($ids[0]);
});

it('names the attribute the board is grouped by in the menu', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);

    $component = livewire(OpportunitiesBoard::class);

    expect($component->html())->toContain('Collapse stage')->not->toContain('Collapse option');

    $component->call('setBoardColumnField', $tier->code);

    expect($component->html())->toContain('Collapse option')->toContain('Hide option')->not->toContain('Collapse stage');
});

it('keeps the stage menu in the page after a rename re-renders the board', function (): void {
    $id = stageIds($this->stageField)[1];

    $component = livewire(OpportunitiesBoard::class)->call('renameBoardColumn', $id, 'Discovery');

    expect(stageMenuOf($component, $id))->toContain('value="Discovery"');
});

it('turns option colors on when the picked color is the one the option already stores', function (): void {
    $id = stageIds($this->stageField)[1];
    stageOption($id)->update(['settings' => ['color' => OptionColor::Blue->value]]);
    $settings = $this->stageField->settings;
    $settings->enable_option_colors = false;
    $this->stageField->update(['settings' => $settings]);

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnColor', $id, OptionColor::Blue->value)
        ->assertHasNoErrors();

    expect($this->stageField->refresh()->settings->enable_option_colors)->toBeTrue()
        ->and(stageOption($id)->settings->color)->toBe(OptionColor::Blue->value);
});

it('rings only the stored color of a stage, never the gray display fallback', function (string $case): void {
    $id = stageIds($this->stageField)[1];
    $stored = match ($case) {
        'none stored' => null,
        'outside the palette' => '#123456',
        'colors off' => OptionColor::Gray->value,
    };
    stageOption($id)->update(['settings' => ['color' => $stored]]);

    if ($case !== 'colors off') {
        $settings = $this->stageField->settings;
        $settings->enable_option_colors = true;
        $this->stageField->update(['settings' => $settings]);
    } else {
        $settings = $this->stageField->settings;
        $settings->enable_option_colors = false;
        $this->stageField->update(['settings' => $settings]);
    }

    $menu = stageMenuOf(livewire(OpportunitiesBoard::class), $id);

    expect($menu)->toContain('data-stage-swatch="'.OptionColor::Gray->value.'"');

    $ringed = substr_count($menu, 'aria-pressed="true"');

    expect($ringed)->toBe($case === 'colors off' ? 1 : 0);
})->with(['none stored', 'outside the palette', 'colors off']);

it('shows a failed rename error only in the menu of the stage it was for', function (): void {
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class)->call('renameBoardColumn', $ids[1], '');

    expect(stageMenuOf($component, $ids[1]))->toContain('data-stage-error')
        ->and(stageMenuOf($component, $ids[2]))->not->toContain('data-stage-error');
});

it('draws the menu dot and swatches through the same color path as the board header', function (): void {
    $id = stageIds($this->stageField)[1];
    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnColor', $id, OptionColor::Olive->value);
    $menu = stageMenuOf($component, $id);

    expect($menu)->toContain('var(--color-500)')->not->toContain('background-color: '.OptionColor::Olive->value);
});

function deleteStageAction(string $id): TestAction
{
    return TestAction::make('deleteStage')->arguments(['column' => $id]);
}

it('deletes a stage, clears its cards and logs each record', function (): void {
    $ids = stageIds($this->stageField);
    $name = stageOption($ids[1])->name;
    $first = opportunityInStage($this, 'Acme renewal', $name);
    $second = opportunityInStage($this, 'Globex upsell', $name);
    $kept = opportunityInStage($this, 'Initech', stageOption($ids[0])->name);

    $logged = fn (): int => Activity::withoutGlobalScopes()->where('event', 'custom_field_changes')->whereIn('subject_id', [$first->getKey(), $second->getKey()])->count();
    $before = $logged();

    $component = livewire(OpportunitiesBoard::class)
        ->callAction(deleteStageAction($ids[1]))
        ->assertHasNoActionErrors();

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($ids[1]))->toBeNull()
        ->and($component->instance()->getBoard()->getColumnIdentifiers())->not->toContain($ids[1])
        ->and($first->refresh()->customFieldValues()->where('custom_field_id', $this->stageField->getKey())->value('string_value'))->toBeNull()
        ->and($kept->refresh()->customFieldValues()->where('custom_field_id', $this->stageField->getKey())->value('string_value'))->toBe($ids[0])
        ->and($logged() - $before)->toBe(2);
});

it('shows the cards of a deleted stage under the no-value column when it is on', function (): void {
    $ids = stageIds($this->stageField);
    $opportunity = opportunityInStage($this, 'Acme renewal', stageOption($ids[1])->name);

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->callAction(deleteStageAction($ids[1]));

    $cards = $component->instance()->getBoard()->getBoardRecords(BoardStateData::NO_VALUE_COLUMN)->pluck('id')->all();

    expect($cards)->toBe([$opportunity->getKey()]);
});

it('deletes an empty stage', function (): void {
    $ids = stageIds($this->stageField);

    livewire(OpportunitiesBoard::class)->callAction(deleteStageAction($ids[2]));

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($ids[2]))->toBeNull();
});

function deleteConfirmation(Testable $component, string $id): array
{
    $action = $component->mountAction(deleteStageAction($id))->instance()->getMountedAction();

    return [$action->getModalHeading(), $action->getModalDescription()];
}

it('words the confirmation with the stage name and the card count, and drops the body for an empty stage', function (): void {
    $ids = stageIds($this->stageField);
    $name = stageOption($ids[1])->name;
    opportunityInStage($this, 'Acme renewal', $name);
    opportunityInStage($this, 'Globex upsell', $name);
    opportunityInStage($this, 'Initech', stageOption($ids[0])->name);

    expect(deleteConfirmation(livewire(OpportunitiesBoard::class), $ids[1]))
        ->toBe(['Delete "'.$name.'"?', '2 opportunities will have no stage. They show in the No Stage column when it is on in View settings.'])
        ->and(deleteConfirmation(livewire(OpportunitiesBoard::class), $ids[0])[1])->toBe('1 opportunity will have no stage. It shows in the No Stage column when it is on in View settings.')
        ->and(deleteConfirmation(livewire(OpportunitiesBoard::class), $ids[2]))->toBe(['Delete "'.stageOption($ids[2])->name.'"?', null]);
});

it('words the confirmation with the option noun on another attribute', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $gold = (string) $tier->options->firstWhere('name', 'Gold')->getKey();
    $opportunity = opportunityInStage($this, 'Acme renewal', stageOption(stageIds($this->stageField)[0])->name);
    $opportunity->saveCustomFieldValue($tier, $gold);

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnField', $tier->code);

    expect(deleteConfirmation($component, $gold)[1])->toBe('1 opportunity will have no option. It shows in the No Tier column when it is on in View settings.');
});

it('never offers delete on the no-value column', function (): void {
    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->assertActionHidden(deleteStageAction(BoardStateData::NO_VALUE_COLUMN));
});

it('hides delete from a member and a viewer and refuses a forged delete', function (WorkspaceRole $role): void {
    $id = stageIds($this->stageField)[1];
    $this->actingAs(makeBoardMember($this, $role));

    $component = livewire(OpportunitiesBoard::class);

    $component->assertActionHidden(deleteStageAction($id))
        ->call('mountAction', 'deleteStage', ['column' => $id])
        ->call('callMountedAction');

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($id))->not->toBeNull()
        ->and($component->html())->not->toContain('Delete stage');
})->with([WorkspaceRole::Member, WorkspaceRole::Viewer]);

it('shows delete to an owner in the menu', function (): void {
    expect(livewire(OpportunitiesBoard::class)->html())->toContain('Delete stage');
});

it('refuses to delete the only option with a notification', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold']);
    $gold = (string) $tier->options->first()->getKey();

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnField', $tier->code)
        ->callAction(deleteStageAction($gold))
        ->assertNotified(__('filament/pages/boards.stage_menu.delete_only', ['noun' => 'option']));

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($gold))->not->toBeNull();
});

it('refuses to delete the last visible stage with a notification', function (): void {
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class);

    foreach (array_slice($ids, 1) as $id) {
        $component->call('setBoardColumnVisible', $id, false);
    }

    $component->callAction(deleteStageAction($ids[0]))
        ->assertNotified(__('filament/pages/boards.stage_menu.delete_last_visible', ['noun' => 'stage']));

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($ids[0]))->not->toBeNull();
});

it('leaves the stored board state harmless when a collapsed, hidden and ordered stage is deleted', function (): void {
    $ids = stageIds($this->stageField);

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnCollapsed', $ids[1], true)
        ->call('setBoardColumnVisible', $ids[2], false)
        ->call('reorderBoardStages', array_reverse($ids))
        ->callAction(deleteStageAction($ids[1]))
        ->callAction(deleteStageAction($ids[2]));

    $state = TablePreference::query()->sole()->board_state;
    $board = $component->instance()->getBoard();

    expect($state->collapsedColumns)->toContain($ids[1])
        ->and($state->hiddenColumns)->toContain($ids[2])
        ->and($board->getColumnIdentifiers())->not->toContain($ids[1])->not->toContain($ids[2])
        ->and($board->getCollapsedColumns())->toBe([]);

    $component->call('setBoardColumnCollapsed', $ids[1], true)->call('setBoardColumnVisible', $ids[2], true);

    livewire(OpportunitiesBoard::class)->assertSuccessful();
});

it('counts only live cards in the confirmation and gives a stage holding only trashed cards no body', function (): void {
    $ids = stageIds($this->stageField);
    $name = stageOption($ids[1])->name;
    opportunityInStage($this, 'Acme renewal', $name);
    opportunityInStage($this, 'Globex upsell', $name)->delete();
    opportunityInStage($this, 'Initech', stageOption($ids[0])->name)->delete();

    expect(deleteConfirmation(livewire(OpportunitiesBoard::class), $ids[1])[1])->toStartWith('1 opportunity will have no stage.')
        ->and(deleteConfirmation(livewire(OpportunitiesBoard::class), $ids[0])[1])->toBeNull();
});

it('opens the delete confirmation at medium width with an icon', function (): void {
    $action = livewire(OpportunitiesBoard::class)->mountAction(deleteStageAction(stageIds($this->stageField)[1]))->instance()->getMountedAction();

    expect($action->getModalWidth())->toBe(Width::Medium)
        ->and($action->getModalIcon())->toBe('lucide-trash-2');
});

it('deletes a stage holding more cards than the assistant cap', function (): void {
    config(['chat.max_option_value_moves' => 1]);
    $ids = stageIds($this->stageField);
    $name = stageOption($ids[1])->name;
    opportunityInStage($this, 'Acme renewal', $name);
    opportunityInStage($this, 'Globex upsell', $name);

    livewire(OpportunitiesBoard::class)->callAction(deleteStageAction($ids[1]));

    expect(CustomFieldOption::query()->withoutGlobalScopes()->find($ids[1]))->toBeNull();
});

it('tells the board user to reload when the options change under a delete, rename or color write', function (string $write): void {
    $ids = stageIds($this->stageField);
    $other = $ids[0];
    $armed = true;

    DB::listen(function (QueryExecuted $query) use (&$armed, $other): void {
        if ($armed && str_contains($query->sql, 'for update') && str_contains($query->sql, 'custom_fields')) {
            $armed = false;
            DB::table('custom_field_options')->where('id', $other)->update(['name' => 'Raced']);
        }
    });

    $component = livewire(OpportunitiesBoard::class);

    $changed = __('filament/pages/boards.stage_menu.changed', ['noun' => 'stage']);

    match ($write) {
        'delete' => $component->callAction(deleteStageAction($ids[1]))->assertNotified($changed),
        'rename' => $component->call('renameBoardColumn', $ids[1], 'Discovery')->assertHasErrors(['boardStageMenu.'.$ids[1] => $changed]),
        'color' => $component->call('setBoardColumnColor', $ids[1], OptionColor::Blue->value)->assertHasErrors(['boardStageMenu.'.$ids[1] => $changed]),
    };

    expect(stageOption($ids[1])->name)->not->toBe('Discovery')->and(CustomFieldOption::query()->withoutGlobalScopes()->find($ids[1]))->not->toBeNull();
})->with(['delete', 'rename', 'color']);

it('adds a gray stage after the last one from the add tile', function (): void {
    $before = stageIds($this->stageField);

    $component = livewire(OpportunitiesBoard::class)
        ->assertSee(__('filament/pages/boards.stage_menu.add', ['noun' => 'stage']))
        ->call('addBoardColumn', '  Negotiation  ')
        ->assertHasNoErrors();

    $added = array_values(array_diff(stageIds($this->stageField), $before));

    expect($added)->toHaveCount(1)
        ->and(stageOption($added[0])->name)->toBe('Negotiation')
        ->and(stageOption($added[0])->settings->color)->toBe(OptionColor::Gray->value)
        ->and(stageIds($this->stageField))->toBe([...$before, $added[0]])
        ->and(array_slice($component->instance()->getBoard()->getColumnIdentifiers(), -1))->toBe($added);
});

it('lands a new stage at the end of a member stored stage order', function (): void {
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class)->call('reorderBoardStages', array_reverse($ids));

    $component->call('addBoardColumn', 'Negotiation');
    $added = array_values(array_diff(stageIds($this->stageField), $ids));

    expect($component->instance()->getBoard()->getColumnIdentifiers())->toBe([...array_reverse($ids), ...$added]);
});

it('shows a taken or blank stage name under the add input and writes nothing', function (string $name): void {
    $count = count(stageIds($this->stageField));
    $taken = stageOption(stageIds($this->stageField)[0])->name;

    livewire(OpportunitiesBoard::class)
        ->call('addBoardColumn', $name === 'taken' ? strtoupper($taken) : '   ')
        ->assertHasErrors(['boardNewColumn']);

    expect(stageIds($this->stageField))->toHaveCount($count);
})->with(['taken', 'blank']);

it('tells the board user to reload when the options change under an add', function (): void {
    $armed = true;
    $first = stageIds($this->stageField)[0];

    DB::listen(function (QueryExecuted $query) use (&$armed, $first): void {
        if ($armed && str_contains($query->sql, 'for update') && str_contains($query->sql, 'custom_fields')) {
            $armed = false;
            DB::table('custom_field_options')->where('id', $first)->update(['name' => 'Raced']);
        }
    });

    livewire(OpportunitiesBoard::class)
        ->call('addBoardColumn', 'Negotiation')
        ->assertHasErrors(['boardNewColumn' => __('filament/pages/boards.stage_menu.changed', ['noun' => 'stage'])]);
});

it('offers no add tile to a member or a viewer and refuses a forged add', function (WorkspaceRole $role): void {
    $count = count(stageIds($this->stageField));
    $this->actingAs(makeBoardMember($this, $role));

    $component = livewire(OpportunitiesBoard::class)->call('addBoardColumn', 'Forged');

    expect($component->instance()->getBoard()->canAddColumns())->toBeFalse()
        ->and($component->html())->not->toContain('ff-add-column')
        ->and(stageIds($this->stageField))->toHaveCount($count);
})->with([WorkspaceRole::Member, WorkspaceRole::Viewer]);

it('words the add tile by the attribute the board is grouped by', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);

    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getBoard()->getAddColumnLabel())->toBe('Add stage');

    $component->call('setBoardColumnField', $tier->code)->call('addBoardColumn', 'Bronze');

    expect($component->instance()->getBoard()->getAddColumnLabel())->toBe('Add option')
        ->and(CustomFieldOption::query()->withoutGlobalScopes()->where('custom_field_id', $tier->getKey())->orderBy('sort_order')->pluck('name')->all())->toBe(['Gold', 'Silver', 'Bronze']);
});

it('stores gray on a new option and leaves option colors off', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    expect($tier->refresh()->settings->enable_option_colors ?? false)->toBeFalse();

    livewire(OpportunitiesBoard::class)->call('setBoardColumnField', $tier->code)->call('addBoardColumn', 'Bronze')->assertHasNoErrors();

    $bronze = CustomFieldOption::query()->withoutGlobalScopes()->where('custom_field_id', $tier->getKey())->where('name', 'Bronze')->firstOrFail();

    expect($bronze->settings->color)->toBe(OptionColor::Gray->value)
        ->and($tier->refresh()->settings->enable_option_colors ?? false)->toBeFalse();
});

it('refuses the add write for a member at the action', function (WorkspaceRole $role): void {
    $member = makeBoardMember($this, $role);
    $options = [...array_map(fn (CustomFieldOption $option): array => ['id' => (string) $option->getKey(), 'name' => (string) $option->name, 'was' => (string) $option->name], $this->stageField->options->all()), ['name' => 'Forged', 'color' => OptionColor::Gray->value]];

    expect(fn () => resolve(SetCustomFieldOptions::class)->execute($member, $this->stageField, ['options' => $options, 'removed' => []]))
        ->toThrow(HttpException::class);

    expect(CustomFieldOption::query()->withoutGlobalScopes()->where('name', 'Forged')->exists())->toBeFalse();
})->with([WorkspaceRole::Member, WorkspaceRole::Viewer]);

it('words a taken stage name with the stage noun and the typed name', function (): void {
    $taken = stageOption(stageIds($this->stageField)[0])->name;

    $component = livewire(OpportunitiesBoard::class)->call('addBoardColumn', strtolower($taken));

    expect($component->errors()->get('boardNewColumn'))->toBe([__('filament/pages/boards.stage_menu.name_taken', ['noun' => 'stage', 'name' => strtolower($taken)])])
        ->and(__('filament/pages/boards.stage_menu.name_taken', ['noun' => 'stage', 'name' => 'Won']))->toBe('A stage named "Won" already exists.');
});

it('words the add input placeholder by the grouped attribute', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getBoard()->getAddColumnPlaceholder())->toBe('New stage name');

    $component->call('setBoardColumnField', $tier->code);

    expect($component->instance()->getBoard()->getAddColumnPlaceholder())->toBe('New option name');
});
