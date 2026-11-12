<?php

declare(strict_types=1);

use App\Actions\Views\ReorderBoardStages;
use App\Data\BoardStateData;
use App\Enums\CreationSource;
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
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
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

it('orders the company filter alphabetically and chips each option', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Zeta Industries']);
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);

    $field = null;

    foreach (livewire(OpportunitiesBoard::class)->instance()->getTableFiltersForm()->getComponents(withHidden: true) as $component) {
        foreach ($component->getChildComponentContainers() as $container) {
            foreach ($container->getComponents(withHidden: true) as $child) {
                if ($child instanceof Select && $child->hasRelationship() && $child->getRelationshipName() === 'company') {
                    $field = $child;
                }
            }
        }
    }

    expect($field)->not->toBeNull()
        ->and($field->isHtmlAllowed())->toBeTrue()
        ->and($field->getOptionLabelFromRecord($acme))->toContain(CrmEntity::Company->iconPath());

    $labels = array_values(array_map(
        fn (string $label): string => trim((string) preg_replace('/\s+/', ' ', strip_tags($label))),
        $field->getOptionsFromRelationship(),
    ));

    expect($labels)->toBe(['Acme Corp', 'Zeta Industries']);
});

it('labels the filter trigger and offers a clear button only while a filter is active', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();

    livewire(OpportunitiesBoard::class)
        ->assertSeeText(__('filament/pages/boards.filters_button.label'))
        ->assertDontSeeHtml('fi-board-filter-clear')
        ->set('tableFilters.companies.values', [$company->getKey()])
        ->assertSeeHtml('fi-board-filter-clear')
        ->assertSeeHtml('wire:click="removeTableFilters"')
        ->call('removeTableFilters')
        ->assertSet('tableFilters.companies.values', [])
        ->assertDontSeeHtml('fi-board-filter-clear');
});

it('keeps the board clear button off the opportunity list table', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();

    $web = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::WEB]);
    $api = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::API]);
    $rules = [['field' => 'creation_source', 'condition' => 'is_any_of', 'value' => [CreationSource::WEB->value]]];

    Livewire::withQueryParams(['view' => 'all'])->test(ListOpportunities::class)
        ->set('tableFilters.conditions.rules', $rules)
        ->assertSet('tableFilters.conditions.rules', $rules)
        ->assertCanSeeTableRecords([$web])
        ->assertCanNotSeeTableRecords([$api])
        ->assertDontSeeHtml('fi-board-filter-clear');
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
