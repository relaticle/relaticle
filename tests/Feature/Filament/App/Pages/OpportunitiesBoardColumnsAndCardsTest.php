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
use App\Models\User;
use App\Queries\Views\BoardCardAttributesQuery;
use App\Queries\Views\BoardColumnFieldsQuery;
use App\Support\CustomFields\WorkspaceCustomFields;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
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
