<?php

declare(strict_types=1);

use App\Actions\Views\ReorderBoardStages;
use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\WorkspaceRole;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Actions\PreviewRecordAction;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\TablePreference;
use App\Models\Task;
use App\Models\User;
use Closure;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\Grouping;
use Relaticle\Flowforge\Lane;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Helpers\WorkspaceCustomField;

mutates(ReorderBoardStages::class, OpportunitiesBoard::class, MoveCardAction::class, PreviewRecordAction::class);

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

    expect(preg_match('/aria-checked="true"[^>]*data-board-grouping="none"/s', $none))->toBe(1)
        ->and(preg_match('/aria-checked="true"[^>]*data-board-grouping="company"/s', $none))->toBe(0)
        ->and(preg_match('/aria-checked="true"[^>]*data-board-grouping="company"/s', $lanes))->toBe(1)
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

it('keeps the hand order and offers no sort while lanes are shown', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    opportunitiesToSort($this);
    Opportunity::query()->update(['company_id' => $company->getKey()]);

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'asc']])
        ->call('setBoardGrouping', 'company');

    $board = $component->instance()->getBoard();
    $lane = $board->getLanes($board->getActiveGrouping())[0];

    expect($component->instance()->getFilterBarData()['sortable'])->toBe([])
        ->and($component->instance()->getFilterBarData()['sorts'])->toBe([])
        ->and($component->get('boardSort'))->toBe('name:asc')
        ->and($board->getLaneRecords($lane, 50)->pluck('name')->all())->toBe(['Bravo', 'Charlie', 'Alpha']);

    $component->call('setBoardGrouping', null);

    expect(opportunityStageNames($component, 'Prospecting'))->toBe(['Alpha', 'Bravo', 'Charlie']);
});

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
        ->call('mountAction', 'create', ['column' => (string) $prospecting->getKey()])
        ->set('mountedActions.0.data.name', 'Fresh deal')
        ->call('callMountedAction')
        ->assertOk();

    expect((float) Opportunity::query()->where('name', 'Fresh deal')->firstOrFail()->order_column)->toBeLessThan(1000.0);
});

it('leaves the hand order of a column untouched by moves made while sorted', function (): void {
    opportunitiesToSort($this);
    $mover = opportunityInStage($this, 'Mover', 'Qualification');
    $prospecting = (string) $this->stageField->options->firstWhere('name', 'Prospecting')->getKey();
    $before = Opportunity::query()->whereIn('name', ['Bravo', 'Charlie', 'Alpha'])->pluck('order_column', 'name')->map(fn ($value): float => (float) $value)->all();

    $component = livewire(OpportunitiesBoard::class)
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']])
        ->call('moveCard', (string) $mover->id, $prospecting)
        ->call('setFilterBarSorts', []);

    $after = Opportunity::query()->whereIn('name', ['Bravo', 'Charlie', 'Alpha'])->pluck('order_column', 'name')->map(fn ($value): float => (float) $value)->all();

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
        ->assertActionDisabled(TestAction::make('create')->arguments(['column' => $targetId]))
        ->call('mountAction', 'create', ['column' => $targetId])
        ->set('mountedActions.0.data.name', 'Rejected deal')
        ->call('callMountedAction')
        ->assertOk();

    expect(Opportunity::query()->where('name', 'Rejected deal')->exists())->toBeFalse();
})->with(['foreign workspace option' => 'foreign', 'garbage id' => 'garbage']);

it('creates a card in a stage option the board owns', function (): void {
    $qualification = $this->stageField->options->firstWhere('name', 'Qualification');

    livewire(OpportunitiesBoard::class)
        ->call('mountAction', 'create', ['column' => (string) $qualification->getKey()])
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
        ->call('mountAction', 'create', ['column' => (string) $prospecting->getKey()])
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

it('stores an order made of the board\'s own stages once each', function (): void {
    $order = stageIds($this->stageField);

    $state = resolve(ReorderBoardStages::class)->execute($this->user, $this->workspace, CrmEntity::Opportunity, [$order[2], $order[0], $order[2]]);

    expect($state->stageOrder)->toBe([$order[2], $order[0]])
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

    expect(opportunityStageCardIds(freshOpportunitiesBoard(), 'Prospecting'))->toBe([$keep->id])
        ->and($drop->id)->not->toBeIn(opportunityStageCardIds(freshOpportunitiesBoard(), 'Prospecting'));
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
