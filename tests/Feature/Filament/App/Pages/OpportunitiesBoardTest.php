<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Actions\Views\PlaceBoardCard;
use App\Actions\Views\ReorderBoardStages;
use App\Actions\Views\SetBoardColumnField;
use App\Actions\Views\SetBoardColumnVisibility;
use App\Actions\Views\SetCardAttributes;
use App\Data\BoardStateData;
use App\Enums\BoardCardSize;
use App\Enums\BoardColumnWidth;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
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
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Export;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\Task;
use App\Models\User;
use App\Queries\Views\BoardCardAttributesQuery;
use App\Queries\Views\BoardColumnFieldsQuery;
use App\Support\CustomFields\WorkspaceCustomFields;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\Grouping;
use Relaticle\Flowforge\Lane;
use Relaticle\Flowforge\Services\DecimalPosition;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Helpers\WorkspaceCustomField;

mutates(SetCardAttributes::class, PlaceBoardCard::class, CreatesTableAttribute::class, BoardCardAttributesQuery::class, HasBoardCards::class, ReorderBoardStages::class, SetBoardColumnField::class, SetBoardColumnVisibility::class, BoardColumnFieldsQuery::class, OpportunitiesBoard::class, MoveCardAction::class, PreviewRecordAction::class, HasBoardViews::class, HasBoardStageOrder::class, HasViewSwitcher::class);

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

function opportunityInStage(object $test, string $name, string $stage, ?Company $company = null): Opportunity
{
    $opportunity = Opportunity::factory()->recycle([$test->user, $test->workspace])->create(['name' => $name, 'company_id' => $company?->getKey()]);
    $opportunity->saveCustomFieldValue($test->stageField, $test->stageField->options->firstWhere('name', $stage)->getKey());

    return $opportunity;
}

function opportunityStageCardIds(Testable $component, string $stage): array
{
    $option = CustomFieldOption::query()->withoutGlobalScopes()->where('name', $stage)->firstOrFail();

    return $component->instance()->getBoard()->getBoardRecords((string) $option->getKey())->pluck('id')->all();
}

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

/**
 * @return list<string>
 */
function stageIds(CustomField $field): array
{
    return CustomFieldOption::query()->withoutGlobalScopes()->where('custom_field_id', $field->getKey())->orderBy('sort_order')->pluck('id')->map(strval(...))->all();
}

/**
 * @return list<string>
 */
function boardColumnOrder(Testable $component): array
{
    return $component->instance()->getBoard()->getColumnIdentifiers();
}

function freshOpportunitiesBoard(): Testable
{
    Once::flush();

    return livewire(OpportunitiesBoard::class);
}

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

function opportunitySelectField(object $test, string $name, array $options): CustomField
{
    return resolve(CreateCustomField::class)->execute($test->user, ['name' => $name, 'type' => 'select', 'entity_type' => 'opportunity', 'options' => array_map(fn (string $option): array => ['name' => $option], $options)]);
}

it('keeps every board setting when stages are reordered', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $tierIds = $tier->options->pluck('id')->map(strval(...))->all();
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(stageOrder: array_reverse($tierIds), hiddenColumns: [$tierIds[0]], noValueColumns: ['stage'], cardAttributes: ['company'], showLabels: true, hideEmpty: false, columnWidth: BoardColumnWidth::Narrow, cardSize: BoardCardSize::Compact));
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
        ->and($stored->columnWidth)->toBe(BoardColumnWidth::Narrow)
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
    $preference = rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: ['company'], showLabels: true, columnWidth: BoardColumnWidth::Narrow));
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

function rememberedBoard(object $test, CrmEntity $entity, BoardStateData $state, ?User $user = null): TablePreference
{
    return TablePreference::factory()->create([
        'user_id' => ($user ?? $test->user)->getKey(),
        'workspace_id' => $test->workspace->getKey(),
        'entity' => $entity,
        'board_state' => $state,
    ]);
}

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

it('groups the board by a custom select', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $gold = $tier->options->firstWhere('name', 'Gold');
    $deal = opportunityInStage($this, 'Gold deal', 'Prospecting');
    $deal->saveCustomFieldValue($tier, $gold->getKey());

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnField', $tier->code);

    expect(boardColumnOrder($component))->toBe(stageIds($tier))
        ->and($component->instance()->getBoard()->getBoardRecords((string) $gold->getKey())->pluck('id')->all())->toBe([$deal->id]);
});

it('remembers the column attribute inside a board view and leaves the plain board on stage', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'type' => SavedViewType::Board,
        'state' => new BoardStateData,
    ]);

    $component = Livewire::withQueryParams(['view' => $view->getKey()])->test(OpportunitiesBoard::class)
        ->call('setBoardColumnField', $tier->code);

    expect($view->refresh()->boardState()->columnField)->toBe($tier->code)
        ->and(TablePreference::query()->count())->toBe(0)
        ->and(boardColumnOrder($component))->toBe(stageIds($tier))
        ->and(boardColumnOrder(Livewire::withQueryParams([])->test(OpportunitiesBoard::class)))->toBe(stageIds($this->stageField));
});

it('keeps a hidden column in its slot when the visible columns are dragged', function (): void {
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', $ids[1], false);

    $component->call('reorderBoardStages', [$ids[2], $ids[0], ...array_slice($ids, 3)]);

    expect(TablePreference::query()->sole()->board_state->stageOrder)->toBe([$ids[2], $ids[1], $ids[0], ...array_slice($ids, 3)])
        ->and(boardColumnOrder($component))->toBe([$ids[2], $ids[0], ...array_slice($ids, 3)]);
});

it('draws, counts and offers no hidden column', function (): void {
    $prospecting = $this->stageField->options->firstWhere('name', 'Prospecting');
    opportunityInStage($this, 'Hidden deal', 'Prospecting');
    $other = opportunityInStage($this, 'Other deal', 'Qualification');

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', (string) $prospecting->getKey(), false);

    expect(boardColumnOrder($component))->not->toContain((string) $prospecting->getKey())
        ->and($component->instance()->getBoard()->getBatchedBoardRecordCounts())->not->toHaveKey((string) $prospecting->getKey());

    $component->call('mountAction', 'moveTo', [], ['recordKey' => (string) $other->id])
        ->assertMountedActionModalSee(['Qualification', 'Closed Won'])
        ->assertMountedActionModalDontSee('Prospecting');
});

it('refuses a card created or moved into a hidden column', function (): void {
    $prospecting = (string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey();
    $card = opportunityInStage($this, 'Moving deal', 'Qualification');

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', $prospecting, false);

    $component->call('mountAction', 'addCard', ['column' => $prospecting])
        ->set('mountedActions.0.data.name', 'Hidden column deal')
        ->call('callMountedAction');

    $component->call('moveCard', (string) $card->id, $prospecting)->assertReturned(false);

    expect(Opportunity::query()->where('name', 'Hidden column deal')->exists())->toBeFalse()
        ->and($card->fresh()->customFieldValues()->where('custom_field_id', $this->stageField->getKey())->value($this->stageField->getValueColumn()))
        ->toBe($this->stageField->options->firstWhere('name', 'Qualification')->getKey());
});

it('refuses to hide the last visible column', function (): void {
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class);

    foreach (array_slice($ids, 1) as $id) {
        $component->call('setBoardColumnVisible', $id, false);
    }

    $component->call('setBoardColumnVisible', $ids[0], false)->assertOk();

    expect(fn () => resolve(SetBoardColumnVisibility::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, OpportunityField::STAGE->value, $ids[0], false))
        ->toThrow(fn (HttpException $exception): bool => $exception->getStatusCode() === 422);

    expect(TablePreference::query()->sole()->board_state->hiddenColumns)->toHaveCount(count($ids) - 1);
});

it('refuses an option of another attribute or workspace', function (string $case): void {
    $foreign = User::factory()->withWorkspace()->create();
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $foreignStage = CustomField::query()->withoutGlobalScopes()->where('tenant_id', $foreign->currentWorkspace->getKey())->where('entity_type', 'opportunity')->where('code', OpportunityField::STAGE)->firstOrFail();

    $optionId = match ($case) {
        'another attribute' => (string) $tier->options->first()->getKey(),
        'another workspace' => stageIds($foreignStage)[0],
        'unknown' => 'not-an-option',
    };

    expect(fn () => resolve(SetBoardColumnVisibility::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, OpportunityField::STAGE->value, $optionId, false))
        ->toThrow(HttpException::class);

    expect(TablePreference::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['another attribute', 'another workspace', 'unknown']);

it('keeps hidden columns per attribute', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $gold = (string) $tier->options->firstWhere('name', 'Gold')->getKey();

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnField', $tier->code)
        ->call('setBoardColumnVisible', $gold, false);

    expect(boardColumnOrder($component))->not->toContain($gold);

    $component->call('setBoardColumnField', null);
    expect(boardColumnOrder($component))->toBe(stageIds($this->stageField));

    $component->call('setBoardColumnField', $tier->code);
    expect(boardColumnOrder($component))->not->toContain($gold);
});

it('shows a column again in the slot it had', function (): void {
    $ids = stageIds($this->stageField);

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', $ids[1], false)
        ->call('setBoardColumnVisible', $ids[1], true);

    expect(boardColumnOrder($component))->toBe($ids)
        ->and(TablePreference::query()->sole()->board_state->hiddenColumns)->toBe([]);
});

it('reads the visible count in view settings', function (): void {
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', $ids[0], false);

    $html = (string) $component->instance()->boardViewSettings();

    expect($html)->toMatch('/data-board-columns-trigger.*?fi-board-settings-value-text">\s*'.(count($ids) - 1).'\s*</s');
});

it('keeps a card with no value for the column attribute while another column is hidden', function (): void {
    $bare = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'No stage deal']);
    $hiddenCard = opportunityInStage($this, 'Hidden stage deal', 'Prospecting');
    $shownCard = opportunityInStage($this, 'Shown stage deal', 'Qualification');
    $prospecting = (string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey();

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', $prospecting, false);
    $names = $component->instance()->getFilteredTableQuery()->pluck('opportunities.name')->all();

    expect($names)->toContain($bare->name, $shownCard->name)
        ->and($names)->not->toContain($hiddenCard->name);
});

it('keeps the records of a hidden column in the board query and in the export query', function (): void {
    $hiddenCard = opportunityInStage($this, 'Hidden stage deal', 'Prospecting');
    $prospecting = (string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey();

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', $prospecting, false);

    expect($component->instance()->getBoard()->getQuery()->pluck('opportunities.name')->all())->toContain($hiddenCard->name)
        ->and($component->instance()->getTableQueryForExport()->pluck('opportunities.name')->all())->toContain($hiddenCard->name);
});

it('draws every column when the stored state hides every option', function (): void {
    $ids = stageIds($this->stageField);
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(hiddenColumns: $ids));

    $component = livewire(OpportunitiesBoard::class);

    expect(boardColumnOrder($component))->toBe($ids)
        ->and((string) $component->instance()->boardViewSettings())->toMatch('/data-board-columns-trigger.*?fi-board-settings-value-text">\s*'.count($ids).'\s*</s')
        ->and(TablePreference::query()->sole()->board_state->hiddenColumns)->toBe($ids);
});

it('lets a member hide a column after the stored state hid every option', function (): void {
    $ids = stageIds($this->stageField);
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(hiddenColumns: $ids));

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', $ids[0], false)->assertOk();

    expect(TablePreference::query()->sole()->board_state->hiddenColumns)->toBe([$ids[0]])
        ->and(boardColumnOrder($component))->toBe(array_slice($ids, 1));
});

it('ignores a hide that would leave no visible column or names an unknown option', function (): void {
    $ids = stageIds($this->stageField);
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(hiddenColumns: array_slice($ids, 1)));

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', $ids[0], false)
        ->call('setBoardColumnVisible', 'not-an-option', false)
        ->assertOk();

    expect(boardColumnOrder($component))->toBe([$ids[0]])
        ->and(TablePreference::query()->sole()->board_state->hiddenColumns)->toBe(array_slice($ids, 1));
});

/**
 * @return list<int|string>
 */
function noStageCardIds(Testable $component): array
{
    return $component->instance()->getBoard()->getBoardRecords(BoardStateData::NO_VALUE_COLUMN)->pluck('id')->all();
}

function noStageOpportunity(object $test, string $name): Opportunity
{
    return Opportunity::factory()->recycle([$test->user, $test->workspace])->create(['name' => $name]);
}

it('shows a no stage column first, holding exactly the opportunities with no stage, once it is switched on', function (): void {
    opportunityInStage($this, 'Acme renewal', 'Prospecting');
    $bare = noStageOpportunity($this, 'Globex upsell');
    $cleared = opportunityInStage($this, 'Initech pilot', 'Prospecting');
    $cleared->saveCustomFieldValue($this->stageField, null);

    $off = livewire(OpportunitiesBoard::class);
    expect(boardColumnOrder($off))->not->toContain(BoardStateData::NO_VALUE_COLUMN);

    $on = $off->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true);

    expect(boardColumnOrder($on)[0])->toBe(BoardStateData::NO_VALUE_COLUMN)
        ->and(noStageCardIds($on))->toEqualCanonicalizing([$bare->id, $cleared->id])
        ->and($on->instance()->getBoard()->getBatchedBoardRecordCounts()[BoardStateData::NO_VALUE_COLUMN])->toBe(2)
        ->and(TablePreference::query()->sole()->board_state->noValueColumns)->toBe(['stage']);
    $on->assertSee('No Stage');
});

it('applies a filter and a sort inside the no stage column', function (): void {
    noStageOpportunity($this, 'Acme alpha');
    noStageOpportunity($this, 'Acme zulu');
    noStageOpportunity($this, 'Globex');

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setFilterRules', [['field' => 'name', 'condition' => 'contains', 'value' => 'Acme']]);

    expect(noStageCardIds($component))->toHaveCount(2);

    $component->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'desc']]);

    expect($component->instance()->getBoard()->getBoardRecords(BoardStateData::NO_VALUE_COLUMN)->pluck('name')->all())->toBe(['Acme zulu', 'Acme alpha']);
});

it('clears the stage when a card moves into the no stage column and sets it when it moves out', function (): void {
    $card = opportunityInStage($this, 'Acme renewal', 'Prospecting');
    $qualification = (string) $this->stageField->options->firstWhere('name', 'Qualification')->getKey();

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true);

    $component->call('moveCard', (string) $card->id, BoardStateData::NO_VALUE_COLUMN)->assertReturned(true);
    expect($card->customFieldValues()->where('custom_field_id', $this->stageField->getKey())->exists())->toBeTrue()
        ->and($card->customFieldValues()->where('custom_field_id', $this->stageField->getKey())->value($this->stageField->getValueColumn()))->toBeNull();

    $component->call('moveCard', (string) $card->id, $qualification)->assertReturned(true);
    expect($card->fresh()->getCustomFieldValue($this->stageField))->toBe($qualification);
});

it('creates an opportunity with no stage from the no stage column', function (): void {
    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('mountAction', 'addCard', ['column' => BoardStateData::NO_VALUE_COLUMN])
        ->set('mountedActions.0.data.name', 'Bare deal')
        ->call('callMountedAction');

    $created = Opportunity::query()->where('name', 'Bare deal')->firstOrFail();

    expect($created->customFieldValues()->where('custom_field_id', $this->stageField->getKey())->value($this->stageField->getValueColumn()))->toBeNull()
        ->and((float) $created->order_column)->toBe((float) DecimalPosition::forEmptyColumn())
        ->and(noStageCardIds($component))->toContain($created->id);
});

it('puts an opportunity created in the no stage column above the ones already there', function (): void {
    noStageOpportunity($this, 'Globex upsell')->forceFill(['order_column' => 5000])->saveQuietly();

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('mountAction', 'addCard', ['column' => BoardStateData::NO_VALUE_COLUMN])
        ->set('mountedActions.0.data.name', 'Bare deal')
        ->call('callMountedAction');

    expect((float) Opportunity::query()->where('name', 'Bare deal')->firstOrFail()->order_column)->toBe((float) DecimalPosition::before('5000'));
});

it('refuses the no stage column as a target while it is off', function (): void {
    $card = opportunityInStage($this, 'Acme renewal', 'Prospecting');

    $component = livewire(OpportunitiesBoard::class);

    $component->call('moveCard', (string) $card->id, BoardStateData::NO_VALUE_COLUMN)->assertReturned(false);
    $component->call('mountAction', 'addCard', ['column' => BoardStateData::NO_VALUE_COLUMN])
        ->set('mountedActions.0.data.name', 'Refused deal')
        ->call('callMountedAction');

    expect($card->fresh()->getCustomFieldValue($this->stageField))->toBe((string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey())
        ->and(Opportunity::query()->where('name', 'Refused deal')->exists())->toBeFalse();
});

it('shows an empty no stage column with its add row when every opportunity has a stage', function (): void {
    opportunityInStage($this, 'Acme renewal', 'Prospecting');

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true);

    expect(noStageCardIds($component))->toBe([])
        ->and(boardColumnOrder($component))->toContain(BoardStateData::NO_VALUE_COLUMN);

    $html = $component->html();
    $start = strpos($html, 'data-column-root="'.BoardStateData::NO_VALUE_COLUMN.'"');
    $column = substr($html, $start, strpos($html, 'data-column-root=', $start + 1) - $start);

    expect($start)->not->toBeFalse()
        ->and($column)->toMatch('/ff-add-row/');
});

it('keeps the no stage column out of the stage order and off a header drag', function (): void {
    $reversed = array_reverse(stageIds($this->stageField));

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('reorderBoardStages', [BoardStateData::NO_VALUE_COLUMN, ...$reversed]);

    expect(TablePreference::query()->sole()->board_state->stageOrder)->toBe($reversed)
        ->and(boardColumnOrder($component))->toBe([BoardStateData::NO_VALUE_COLUMN, ...$reversed]);
});

it('counts the no stage column in the visible columns number while it is on', function (): void {
    $count = count(stageIds($this->stageField));

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true);

    expect((string) $component->instance()->boardViewSettings())->toMatch('/data-board-columns-trigger.*?fi-board-settings-value-text">\s*'.($count + 1).'\s*</s')
        ->and((string) $component->instance()->boardViewSettings())->toContain('data-board-no-value-toggle');
});

it('leaves the stage board alone when the column is on for another attribute', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $card = opportunityInStage($this, 'Acme renewal', 'Prospecting');

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnField', $tier->code)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnField', null);

    expect(boardColumnOrder($component))->toBe(stageIds($this->stageField))
        ->and(opportunityStageCardIds($component, 'Prospecting'))->toBe([$card->id]);
});

it('keeps the default board unchanged while the column is off', function (): void {
    $component = livewire(OpportunitiesBoard::class);

    expect($component->instance()->getBoard()->getColumnIdentifierAttribute())->toBe($this->stageField->getValueColumn());
});

it('moves a card into the no stage column and to another company lane in one drop', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    $globex = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);
    $card = opportunityInStage($this, 'Acme renewal', 'Prospecting', $acme);
    opportunityInStage($this, 'Globex upsell', 'Prospecting', $globex);

    livewire(OpportunitiesBoard::class)
        ->call('setBoardGrouping', 'company')
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('moveCard', (string) $card->id, BoardStateData::NO_VALUE_COLUMN, null, null, (string) $acme->id, (string) $globex->id)
        ->assertReturned(true);

    expect($card->fresh()->getCustomFieldValue($this->stageField))->toBeNull()
        ->and($card->fresh()->company_id)->toBe($globex->id);
});

it('refuses the no stage column as a target when it is on only for another attribute', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $card = opportunityInStage($this, 'Acme renewal', 'Prospecting');

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnField', $tier->code)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnField', null);

    $component->call('moveCard', (string) $card->id, BoardStateData::NO_VALUE_COLUMN)->assertReturned(false);

    expect($card->fresh()->getCustomFieldValue($this->stageField))->toBe((string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey());
});

it('keeps the last real column locked while the no stage column is on', function (): void {
    $ids = stageIds($this->stageField);
    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true);

    foreach (array_slice($ids, 1) as $id) {
        $component->call('setBoardColumnVisible', $id, false);
    }

    $html = (string) $component->instance()->boardViewSettings();
    $start = strpos($html, 'data-stage-id="'.$ids[0].'"');
    $row = substr($html, $start, strpos($html, 'data-stage-id=', $start + 1) - $start);

    expect($row)->toContain('board-last-column-hint')
        ->and($html)->toMatch('/data-board-columns-trigger.*?fi-board-settings-value-text">\s*2\s*</s');
});

it('offers the list\'s import, export and new opportunity actions in the board header', function (): void {
    livewire(OpportunitiesBoard::class)
        ->assertActionVisible('create')
        ->assertActionVisible('export')
        ->assertActionVisible('import');
});

it('shows a viewer none of the header actions', function (): void {
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(OpportunitiesBoard::class)
        ->assertActionHidden('create')
        ->assertActionHidden('export')
        ->assertActionHidden('import');
});

function headerCreateOpportunity(string $name, array $data = []): void
{
    livewire(OpportunitiesBoard::class)
        ->mountAction('create')
        ->setActionData(['name' => $name, ...$data])
        ->callMountedAction()
        ->assertHasNoActionErrors();
}

function headerOpportunityPosition(string $name): ?float
{
    $position = Opportunity::query()->where('name', $name)->firstOrFail()->order_column;

    return $position === null ? null : (float) $position;
}

it('creates an opportunity from the header into the first visible stage', function (): void {
    $ids = stageIds($this->stageField);

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', $ids[0], false)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->mountAction('create')
        ->assertSchemaStateSet(['custom_fields.'.OpportunityField::STAGE->value => $ids[1]])
        ->setActionData(['name' => 'Header deal'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $cards = livewire(OpportunitiesBoard::class)->instance()->getBoard()->getBoardRecords($ids[1]);

    expect($cards->pluck('name')->all())->toContain('Header deal');
});

it('puts an opportunity created from the header below the last card of its column', function (): void {
    opportunityInStage($this, 'Acme renewal', 'Prospecting')->forceFill(['order_column' => 3000])->saveQuietly();

    headerCreateOpportunity('Header deal');

    expect(headerOpportunityPosition('Header deal'))->toBe((float) DecimalPosition::after('3000'));
});

it('gives the first opportunity created from the header into an empty column the empty column position', function (): void {
    headerCreateOpportunity('Header deal');

    expect(headerOpportunityPosition('Header deal'))->toBe((float) DecimalPosition::forEmptyColumn());
});

it('sorts a second opportunity created from the header after the first', function (): void {
    headerCreateOpportunity('First header deal');
    headerCreateOpportunity('Second header deal');

    expect(headerOpportunityPosition('Second header deal'))->toBeGreaterThan(headerOpportunityPosition('First header deal'));
});

it('positions an opportunity created from the header in the stage the form was changed to', function (): void {
    opportunityInStage($this, 'Acme renewal', 'Prospecting')->forceFill(['order_column' => 3000])->saveQuietly();
    opportunityInStage($this, 'Globex upsell', 'Qualification')->forceFill(['order_column' => 9000])->saveQuietly();
    $qualification = (string) $this->stageField->options->firstWhere('name', 'Qualification')->getKey();

    headerCreateOpportunity('Header deal', ['custom_fields' => [OpportunityField::STAGE->value => $qualification]]);

    expect(headerOpportunityPosition('Header deal'))->toBe((float) DecimalPosition::after('9000'));
});

it('positions an opportunity created from the header with no stage in the no stage column', function (): void {
    noStageOpportunity($this, 'Globex upsell')->forceFill(['order_column' => 5000])->saveQuietly();

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->mountAction('create')
        ->setActionData(['name' => 'Header deal', 'custom_fields' => [OpportunityField::STAGE->value => null]])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(headerOpportunityPosition('Header deal'))->toBe((float) DecimalPosition::after('5000'));
});

it('creates no opportunity when a viewer calls the header create action directly', function (): void {
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'create')
        ->set('mountedActions.0.data.name', 'Viewer deal')
        ->call('callMountedAction');

    expect(Opportunity::query()->where('name', 'Viewer deal')->exists())->toBeFalse();
});

it('exports the opportunities the bar\'s filters match from the board, hidden stages and no stage included', function (bool $noValueColumn): void {
    opportunityInStage($this, 'Acme hidden stage', 'Prospecting');
    opportunityInStage($this, 'Acme shown stage', 'Qualification');
    noStageOpportunity($this, 'Acme no stage');
    opportunityInStage($this, 'Globex upsell', 'Qualification');
    $prospecting = (string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey();

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', $prospecting, false);

    if ($noValueColumn) {
        $component->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true);
    }

    $component
        ->call('setFilterRules', [['field' => 'name', 'condition' => 'contains', 'value' => 'Acme']])
        ->callAction('export');

    expect(Export::query()->sole()->total_rows)->toBe(3);
})->with([
    'no stage column off' => [false],
    'no stage column on' => [true],
]);

function opportunityWithCardValues(object $test, string $name): Opportunity
{
    $opportunity = opportunityInStage($test, $name, 'Prospecting');
    $workspaceId = $test->workspace->getKey();
    $opportunity->saveCustomFieldValue(WorkspaceCustomField::byCode($workspaceId, 'opportunity', 'amount'), 5000);
    $opportunity->saveCustomFieldValue(WorkspaceCustomField::byCode($workspaceId, 'opportunity', 'close_date'), Date::parse('2031-03-04', 'UTC'));

    return $opportunity;
}

it('draws the chosen attributes in their order', function (): void {
    $opportunity = opportunityWithCardValues($this, 'Order deal');
    $opportunity->update(['contact_id' => People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Priya Contact'])->getKey()]);

    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: ['custom_fields.close_date', 'contact', 'created_at']));

    livewire(OpportunitiesBoard::class)
        ->assertSeeInOrder(['Mar 4', 'Priya Contact'])
        ->assertDontSee('5,000');
});

it('shows a label before each attribute when labels are on', function (): void {
    opportunityWithCardValues($this, 'Labelled deal');
    $component = livewire(OpportunitiesBoard::class);

    expect($component->html())->not->toMatch('/fi-sc-text[^>]*>\s*Amount\s*</');

    $component->call('setCardLabels', true);

    expect($component->html())->toMatch('/fi-sc-text[^>]*>\s*Amount\s*<.*\$5,000.*fi-sc-text[^>]*>\s*Close Date\s*<.*Mar 4/s');
});

it('draws an empty attribute with its label when empty attributes are kept', function (): void {
    opportunityInStage($this, 'Bare deal', 'Prospecting');
    $component = livewire(OpportunitiesBoard::class);

    expect($component->html())->not->toMatch('/fi-sc-text[^>]*>\s*Empty\s*</');

    $component->call('setHideEmptyAttributes', false);

    expect($component->html())->toMatch('/fi-sc-text[^>]*>\s*Amount\s*<.*fi-sc-text[^>]*>\s*Empty\s*<.*fi-sc-text[^>]*>\s*Close Date\s*<.*fi-sc-text[^>]*>\s*Empty\s*</s');
    expect(TablePreference::query()->sole()->board_state->hideEmpty)->toBeFalse();
});

it('keeps an empty attribute in its chosen place among the filled ones', function (): void {
    $opportunity = opportunityInStage($this, 'Gap deal', 'Prospecting');
    $opportunity->saveCustomFieldValue(WorkspaceCustomField::byCode($this->workspace->getKey(), 'opportunity', 'close_date'), Date::parse('2031-03-04', 'UTC'));

    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: ['custom_fields.amount', 'custom_fields.close_date'], hideEmpty: false));

    expect(livewire(OpportunitiesBoard::class)->html())
        ->toMatch('/fi-sc-text[^>]*>\s*Amount\s*<.*fi-sc-text[^>]*>\s*Empty\s*<.*Mar 4/s');
});

it('draws a card with its title only when every attribute is removed', function (): void {
    opportunityWithCardValues($this, 'Title only deal');

    livewire(OpportunitiesBoard::class)
        ->call('removeCardAttribute', 'company')
        ->call('removeCardAttribute', 'custom_fields.amount')
        ->call('removeCardAttribute', 'custom_fields.close_date')
        ->assertSee('Title only deal')
        ->assertDontSee('5,000')
        ->assertDontSee('Mar 4');

    expect(TablePreference::query()->sole()->board_state->cardAttributes)->toBe([]);
});

it('marks the board narrow and compact once both toggles are set', function (): void {
    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnWidth', 'narrow')
        ->call('setBoardCardSize', 'compact')
        ->assertSeeHtml('data-board-column-width="narrow"')
        ->assertSeeHtml('data-board-card-size="compact"');

    expect($component->html())
        ->toMatch('/data-board-column-width-option="narrow"[^>]*aria-pressed="true"|aria-pressed="true"[^>]*data-board-column-width-option="narrow"/')
        ->toMatch('/data-board-card-size-option="compact"[^>]*aria-pressed="true"|aria-pressed="true"[^>]*data-board-card-size-option="compact"/')
        ->toMatch('/data-board-column-width-option="wide"[^>]*aria-pressed="false"|aria-pressed="false"[^>]*data-board-column-width-option="wide"/');

    $state = TablePreference::query()->sole()->board_state;

    expect($state->columnWidth)->toBe(BoardColumnWidth::Narrow)
        ->and($state->cardSize)->toBe(BoardCardSize::Compact);
});

it('draws a compact card with its title only', function (): void {
    opportunityWithCardValues($this, 'Compact deal');

    livewire(OpportunitiesBoard::class)
        ->assertSee('5,000')
        ->call('setBoardCardSize', 'compact')
        ->assertSee('Compact deal')
        ->assertDontSee('5,000')
        ->assertDontSee('Mar 4');
});

it('ignores a width or size the board does not have', function (mixed $value): void {
    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnWidth', $value)
        ->call('setBoardCardSize', $value);

    expect(TablePreference::query()->count())->toBe(0);
})->with(['sideways', 3, null, [['wide']]]);

it('writes nothing when a layout toggle is set to the value it already holds', function (): void {
    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnWidth', 'wide')
        ->call('setBoardCardSize', 'full');

    expect(TablePreference::query()->count())->toBe(0);
});

it('opens wide and full by default', function (): void {
    $component = livewire(OpportunitiesBoard::class)
        ->assertSeeHtml('data-board-column-width="wide"')
        ->assertSeeHtml('data-board-card-size="full"');

    expect($component->html())
        ->toMatch('/data-board-column-width-option="wide"[^>]*aria-pressed="true"|aria-pressed="true"[^>]*data-board-column-width-option="wide"/')
        ->toMatch('/data-board-card-size-option="full"[^>]*aria-pressed="true"|aria-pressed="true"[^>]*data-board-card-size-option="full"/');
});

it('keeps the layout inside a board view and leaves the plain board alone', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'type' => SavedViewType::Board,
        'state' => new BoardStateData,
    ]);

    Livewire::withQueryParams(['view' => $view->getKey()])->test(OpportunitiesBoard::class)
        ->call('setBoardColumnWidth', 'narrow')
        ->call('setBoardCardSize', 'compact')
        ->assertSeeHtml('data-board-column-width="narrow"');

    $stored = $view->refresh()->boardState();

    expect($stored->columnWidth)->toBe(BoardColumnWidth::Narrow)
        ->and($stored->cardSize)->toBe(BoardCardSize::Compact)
        ->and(TablePreference::query()->count())->toBe(0);
});

function cardAttributesStatus(Closure $call): ?int
{
    try {
        $call();
    } catch (HttpException $exception) {
        return $exception->getStatusCode();
    }

    return null;
}

it('ignores a key the card cannot show without an error', function (string $case): void {
    $foreign = User::factory()->withWorkspace()->create();
    $foreignField = resolve(CreateCustomField::class)->execute($foreign, ['name' => 'Foreign tier', 'type' => 'text', 'entity_type' => 'opportunity']);
    $richText = resolve(CreateCustomField::class)->execute($this->user, ['name' => 'Notes', 'type' => 'rich-editor', 'entity_type' => 'opportunity']);
    $record = resolve(CreateCustomField::class)->execute($this->user, ['name' => 'Employer', 'type' => 'record', 'entity_type' => 'opportunity', 'lookup_type' => 'company']);
    $secret = CustomField::factory()->create(['tenant_id' => $this->workspace->getKey(), 'entity_type' => 'opportunity', 'code' => 'secret', 'name' => 'Secret', 'type' => 'text', 'settings' => new CustomFieldSettingsData(encrypted: true)]);

    $key = match ($case) {
        'another entity' => 'assignees',
        'another workspace' => 'custom_fields.'.$foreignField->code,
        'rich editor' => 'custom_fields.'.$richText->code,
        'record' => 'custom_fields.'.$record->code,
        'encrypted' => 'custom_fields.'.$secret->code,
        'unknown' => 'bogus',
        'not a string' => ['x'],
    };

    livewire(OpportunitiesBoard::class)->call('addCardAttribute', $key)->assertOk();

    expect(TablePreference::query()->withoutGlobalScopes()->count())->toBe(0);
})->with(['another entity', 'another workspace', 'rich editor', 'record', 'encrypted', 'unknown', 'not a string']);

it('answers 422 when the action is given a key the card cannot show', function (array $keys): void {
    expect(cardAttributesStatus(fn () => resolve(SetCardAttributes::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, $keys)))->toBe(422);
})->with([
    'another entity' => [['assignees']],
    'not a string' => [[['x']]],
    'a good key beside a bad one' => [['company', 'bogus']],
]);

it('skips a stored attribute that was deleted, deactivated or encrypted since', function (): void {
    $fields = collect(['Gone' => 'GONE-VALUE', 'Off' => 'OFF-VALUE', 'Locked' => 'LOCKED-VALUE'])
        ->map(fn (string $value, string $name): CustomField => resolve(CreateCustomField::class)->execute($this->user, ['name' => $name, 'type' => 'text', 'entity_type' => 'opportunity']));
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Stale Corp']);
    $opportunity = opportunityInStage($this, 'Stale deal', 'Prospecting', $company);

    foreach (['Gone' => 'GONE-VALUE', 'Off' => 'OFF-VALUE', 'Locked' => 'LOCKED-VALUE'] as $name => $value) {
        $opportunity->saveCustomFieldValue($fields[$name], $value);
    }

    $keys = [...$fields->map(fn (CustomField $field): string => 'custom_fields.'.$field->code)->values()->all(), 'company'];
    $preference = rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: $keys));
    $updatedAt = $preference->updated_at;

    freshOpportunitiesBoard()->assertSee(['GONE-VALUE', 'OFF-VALUE', 'LOCKED-VALUE']);

    $fields['Gone']->delete();
    $fields['Off']->forceFill(['active' => false])->save();
    $fields['Locked']->forceFill(['settings' => new CustomFieldSettingsData(encrypted: true)])->save();
    resolve(WorkspaceCustomFields::class)->forget($this->workspace->getKey());

    freshOpportunitiesBoard()->assertOk()->assertSee('Stale Corp')->assertDontSee(['GONE-VALUE', 'OFF-VALUE', 'LOCKED-VALUE']);

    expect($preference->refresh()->updated_at)->toEqual($updatedAt)
        ->and($preference->board_state->cardAttributes)->toBe($keys);
});

it('offers the column attribute to no card while it makes the columns', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $deal = opportunityInStage($this, 'Tiered deal', 'Prospecting');
    $deal->saveCustomFieldValue($tier, $tier->options->firstWhere('name', 'Gold')->getKey());
    $tierKey = 'custom_fields.'.$tier->code;

    $component = livewire(OpportunitiesBoard::class)->call('addCardAttribute', $tierKey);

    expect($component->html())->toContain('Gold');

    $component->call('setBoardColumnField', $tier->code);
    $skipped = substr_count($component->html(), 'Gold');

    $component->call('removeCardAttribute', $tierKey);

    expect(substr_count($component->html(), 'Gold'))->toBe($skipped);
});

it('keeps card settings inside a board view and leaves the plain board alone', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'type' => SavedViewType::Board,
        'state' => new BoardStateData(cardAttributes: ['company']),
    ]);

    Livewire::withQueryParams(['view' => $view->getKey()])->test(OpportunitiesBoard::class)
        ->call('addCardAttribute', 'contact')
        ->call('setCardLabels', true)
        ->call('setHideEmptyAttributes', false);

    $stored = $view->refresh()->boardState();

    expect($stored->cardAttributes)->toBe(['company', 'contact'])
        ->and($stored->showLabels)->toBeTrue()
        ->and($stored->hideEmpty)->toBeFalse()
        ->and(TablePreference::query()->count())->toBe(0);
});

function opportunityWithEveryRelation(object $test): Opportunity
{
    $opportunity = opportunityInStage($test, 'Related deal', 'Prospecting', Company::factory()->recycle([$test->user, $test->workspace])->create());
    $opportunity->update(['contact_id' => People::factory()->recycle([$test->user, $test->workspace])->create()->getKey(), 'creator_id' => $test->user->getKey()]);

    return $opportunity;
}

function countBoardQueries(): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    freshOpportunitiesBoard()->assertOk();
    $queries = array_column(DB::getQueryLog(), 'query');
    DB::disableQueryLog();

    return $queries;
}

it('reads every attribute a card can show without a query per card', function (): void {
    $keys = resolve(BoardCardAttributesQuery::class)->get($this->user, $this->workspace, CrmEntity::Opportunity);
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: $keys));

    opportunityWithEveryRelation($this);
    countBoardQueries();
    $withOneCard = count(countBoardQueries());

    foreach (range(1, 4) as $ignored) {
        opportunityWithEveryRelation($this);
    }

    expect(countBoardQueries())->toHaveCount($withOneCard);
});

it('loads no card relations for a compact card', function (): void {
    $keys = resolve(BoardCardAttributesQuery::class)->get($this->user, $this->workspace, CrmEntity::Opportunity);
    $preference = rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: $keys));
    opportunityWithEveryRelation($this);

    $full = implode("\n", countBoardQueries());
    $preference->update(['board_state' => new BoardStateData(cardAttributes: $keys, cardSize: BoardCardSize::Compact)]);
    $compact = implode("\n", countBoardQueries());

    expect($full)->toContain('from "media"')->toContain('from "people"')
        ->and($compact)->not->toContain('from "media"')->not->toContain('from "people"');
});

it('shows the new view\'s card settings in the same request that opens it', function (): void {
    $workspaceId = $this->workspace->getKey();
    $opportunity = opportunityInStage($this, 'Switch deal', 'Prospecting');
    $opportunity->saveCustomFieldValue(WorkspaceCustomField::byCode($workspaceId, 'opportunity', 'amount'), 5000);
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: ['contact']));
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'type' => SavedViewType::Board,
        'state' => new BoardStateData,
    ]);

    $component = livewire(OpportunitiesBoard::class)->assertDontSee('$5,000.00');

    $component->call('applySavedView', $view->getKey())->assertSee('$5,000.00');

    $component->call('applyOwnBoard')->assertDontSee('$5,000.00');
});

it('lists the card attributes in order with the two switches', function (): void {
    livewire(OpportunitiesBoard::class)
        ->assertSeeHtmlInOrder([
            'data-board-show-labels',
            'data-board-hide-empty',
            'data-card-attribute="company"',
            'data-card-attribute="custom_fields.amount"',
            'data-card-attribute="custom_fields.close_date"',
            'data-board-add-attribute',
        ]);
});

it('offers every attribute the card does not show in the add panel', function (): void {
    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml('data-board-add-item="contact"')
        ->assertDontSeeHtml('data-board-add-item="company"')
        ->assertDontSeeHtml('data-board-add-item="custom_fields.'.OpportunityField::STAGE->value.'"');
});

it('reads the two switches from the stored card settings', function (): void {
    $html = (string) livewire(OpportunitiesBoard::class)->call('setCardLabels', true)->call('setHideEmptyAttributes', false)->instance()->boardViewSettings();

    expect($html)->toMatch('/data-board-show-labels[^>]*data-visible="true"/s')
        ->toMatch('/data-board-hide-empty[^>]*data-visible="false"/s');
});

it('creates an attribute from the board and puts it on the card', function (): void {
    livewire(OpportunitiesBoard::class)
        ->callAction('createTableAttribute', data: ['name' => 'Lead score', 'type' => 'text'])
        ->assertHasNoActionErrors()
        ->assertNotified('Lead score added to this view');

    $opportunity = opportunityInStage($this, 'Scored deal', 'Prospecting');
    $opportunity->saveCustomFieldValue(WorkspaceCustomField::byCode($this->workspace->getKey(), 'opportunity', 'lead_score'), 'Hot 87');

    expect(TablePreference::query()->sole()->board_state->cardAttributes)->toBe([...CrmEntity::Opportunity->defaultCardAttributes(), 'custom_fields.lead_score']);

    livewire(OpportunitiesBoard::class)->assertSee('Hot 87');
});

it('creates a rich text attribute from the board without putting it on the card', function (): void {
    livewire(OpportunitiesBoard::class)
        ->callAction('createTableAttribute', data: ['name' => 'Deal notes', 'type' => 'rich-editor'])
        ->assertHasNoActionErrors()
        ->assertNotified('Deal notes created. This type of attribute does not show on a card.');

    $stored = TablePreference::query()->first()?->board_state->cardAttributes ?? [];

    expect(CustomField::query()->where('entity_type', 'opportunity')->where('name', 'Deal notes')->exists())->toBeTrue()
        ->and($stored)->not->toContain('custom_fields.deal_notes');
});

it('creates a select attribute from grouped by and groups the columns by it', function (): void {
    opportunityInStage($this, 'Untiered deal', 'Prospecting');

    livewire(OpportunitiesBoard::class)
        ->assertSeeHtml('data-board-create-column-field')
        ->mountAction('createColumnAttribute')
        ->assertSchemaStateSet(['type' => 'select'])
        ->assertFormFieldDisabled('type')
        ->fillForm(['name' => 'Tier', 'options' => [['name' => 'Gold'], ['name' => 'Silver']]])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Tier created. The columns are now grouped by it.')
        ->assertSeeHtml('data-column-id="'.CustomFieldOption::query()->where('name', 'Gold')->sole()->getKey().'"')
        ->assertSeeHtml('data-column-id="'.BoardStateData::NO_VALUE_COLUMN.'"')
        ->assertSee('Untiered deal');

    $tier = CustomField::query()->where('entity_type', 'opportunity')->where('name', 'Tier')->sole();
    $state = TablePreference::query()->sole()->board_state;

    expect($tier->type)->toBe('select')
        ->and($state->columnField)->toBe($tier->code)
        ->and($state->noValueColumns)->toBe([$tier->code]);
});

it('creates a select attribute from grouped by whatever type the form sends', function (): void {
    livewire(OpportunitiesBoard::class)
        ->callAction('createColumnAttribute', data: ['name' => 'Lead score', 'type' => 'text', 'options' => [['name' => 'Hot']]])
        ->assertHasNoActionErrors();

    expect(CustomField::query()->where('entity_type', 'opportunity')->where('name', 'Lead score')->sole()->type)->toBe('select');
});

it('refuses a grouped by attribute without options', function (): void {
    livewire(OpportunitiesBoard::class)
        ->callAction('createColumnAttribute', data: ['name' => 'Tier', 'options' => []])
        ->assertHasActionErrors(['options']);

    expect(CustomField::query()->where('name', 'Tier')->exists())->toBeFalse()
        ->and(TablePreference::query()->first()?->board_state->columnField)->toBeNull();
});

it('offers a viewer the card settings but no create row', function (): void {
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    $component = livewire(OpportunitiesBoard::class)
        ->assertSeeHtml('data-board-add-attribute')
        ->assertDontSeeHtml('data-view-settings-create')
        ->assertActionHidden('createTableAttribute')
        ->assertDontSeeHtml('createTableAttribute')
        ->assertActionHidden('createColumnAttribute')
        ->assertDontSeeHtml('data-board-create-column-field')
        ->call('setCardLabels', true);

    expect(TablePreference::query()->sole()->board_state->showLabels)->toBeTrue();
});

it('keeps the column attribute on the card across a write while it makes the columns', function (string $change): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $tierKey = 'custom_fields.'.$tier->code;
    $deal = opportunityInStage($this, 'Tiered deal', 'Prospecting');
    $deal->saveCustomFieldValue($tier, $tier->options->firstWhere('name', 'Gold')->getKey());
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: ['company', $tierKey, 'custom_fields.amount']));

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnField', $tier->code);

    match ($change) {
        'create' => $component->callAction('createTableAttribute', data: ['name' => 'Lead score', 'type' => 'text']),
        'reorder' => $component->call('reorderCardAttributes', ['custom_fields.amount', 'company']),
        'remove' => $component->call('removeCardAttribute', 'company'),
        'add' => $component->call('addCardAttribute', 'contact'),
    };

    $component->call('setBoardColumnField', OpportunityField::STAGE->value);

    expect(TablePreference::query()->sole()->board_state->cardAttributes)->toContain($tierKey);
})->with(['create', 'reorder', 'remove', 'add']);

it('stores two adds in a row from one rendered page', function (): void {
    $component = livewire(OpportunitiesBoard::class);

    $component->call('addCardAttribute', 'contact')->call('addCardAttribute', 'creator');

    expect(TablePreference::query()->sole()->board_state->cardAttributes)->toBe([...CrmEntity::Opportunity->defaultCardAttributes(), 'contact', 'creator']);
});

it('refills only the drawn slots when the order changes', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $tierKey = 'custom_fields.'.$tier->code;
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: ['company', $tierKey, 'custom_fields.amount']));

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnField', $tier->code)
        ->call('reorderCardAttributes', ['custom_fields.amount', 'company']);

    expect(TablePreference::query()->sole()->board_state->cardAttributes)->toBe(['custom_fields.amount', $tierKey, 'company']);
});

it('keeps a deactivated attribute on the stored card and draws it again once it is active', function (): void {
    $score = resolve(CreateCustomField::class)->execute($this->user, ['name' => 'Lead score', 'type' => 'text', 'entity_type' => 'opportunity']);
    $scoreKey = 'custom_fields.'.$score->code;
    opportunityInStage($this, 'Scored deal', 'Prospecting')->saveCustomFieldValue($score, 'Hot 87');
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: ['company', $scoreKey]));

    $score->forceFill(['active' => false])->save();
    resolve(WorkspaceCustomFields::class)->forget($this->workspace->getKey());

    livewire(OpportunitiesBoard::class)->assertDontSee('Hot 87')->call('addCardAttribute', 'contact');

    expect(TablePreference::query()->sole()->board_state->cardAttributes)->toBe(['company', $scoreKey, 'contact']);

    $score->forceFill(['active' => true])->save();
    resolve(WorkspaceCustomFields::class)->forget($this->workspace->getKey());

    livewire(OpportunitiesBoard::class)->assertSee('Hot 87');
});

it('stores an empty card when the last drawn attribute is removed', function (): void {
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: ['company']));

    livewire(OpportunitiesBoard::class)->call('removeCardAttribute', 'company');

    expect(TablePreference::query()->sole()->board_state->cardAttributes)->toBe([]);
});

it('ignores a remove or a reorder that is not a list of strings', function (): void {
    rememberedBoard($this, CrmEntity::Opportunity, new BoardStateData(cardAttributes: ['company', 'contact']));

    livewire(OpportunitiesBoard::class)
        ->call('removeCardAttribute', ['company'])
        ->call('reorderCardAttributes', 'contact')
        ->call('reorderCardAttributes', ['contact', 7]);

    expect(TablePreference::query()->sole()->board_state->cardAttributes)->toBe(['company', 'contact']);
});
