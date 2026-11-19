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
use App\Filament\Resources\OpportunityResource\Pages\OpportunitiesBoard;
use App\Filament\Tables\Concerns\CreatesTableAttribute;
use App\Models\ActivityLog\Activity;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\User;
use App\Queries\Views\BoardCardAttributesQuery;
use App\Queries\Views\BoardColumnFieldsQuery;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\CustomFields\Data\CustomFieldOptionSettingsData;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

it('opens only the clicked stage out of collapse all and keeps the others collapsed, on a click or a double-click', function (bool $doubleClick): void {
    $ids = stageIds($this->stageField);

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnsCollapsed', true)
        ->call('toggleBoardColumnCollapse', $ids[2], $doubleClick);

    $state = TablePreference::query()->sole()->board_state;

    expect($state->columnsCollapsed)->toBeFalse()
        ->and($state->collapsedColumns)->toEqualCanonicalizing(array_values(array_diff($ids, [$ids[2]])))
        ->and($state->collapsedNoValueColumns)->toBe([$this->stageField->code]);
})->with(['click' => false, 'double-click' => true]);

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

it('opens the stages collapsed one by one from the bar, with or without collapse all', function (bool $all): void {
    $ids = stageIds($this->stageField);

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnCollapsed', $ids[1], true)
        ->call('setBoardColumnCollapsed', BoardStateData::NO_VALUE_COLUMN, true);

    if ($all) {
        $component->call('setBoardColumnsCollapsed', true);
    }

    expect(TablePreference::query()->sole()->board_state->collapsedColumns)->toBe([$ids[1]]);

    $component->call('setBoardColumnsCollapsed', false);

    $state = TablePreference::query()->sole()->board_state;

    expect($state->columnsCollapsed)->toBeFalse()
        ->and($state->collapsedColumns)->toBe([])
        ->and($state->collapsedNoValueColumns)->toBe([]);
})->with(['with collapse all' => true, 'without' => false]);

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

it('keeps the no-value collapse of another attribute when the bar opens every column', function (): void {
    $tier = opportunitySelectField($this, 'Tier', ['Gold', 'Silver']);
    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnField', $tier->code)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnCollapsed', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnField', $this->stageField->code)
        ->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnCollapsed', BoardStateData::NO_VALUE_COLUMN, true)
        ->call('setBoardColumnsCollapsed', false);

    expect(TablePreference::query()->sole()->board_state->collapsedNoValueColumns)->toBe([$tier->code]);
});

it('writes nothing when the bar sets the collapse state the board already has', function (bool $collapsed): void {
    $component = livewire(OpportunitiesBoard::class);

    if ($collapsed) {
        $component->call('setBoardColumnsCollapsed', true);
    }

    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        $writes += preg_match('/^(insert into|update) "table_preferences"/', $query->sql);
    });

    $component->call('setBoardColumnsCollapsed', $collapsed);

    expect($writes)->toBe(0);
})->with(['collapsed' => true, 'expanded' => false]);

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
        ->call('setBoardColumnColor', $id, OptionColor::Red->value)
        ->assertHasNoErrors();

    expect(stageOption($id)->settings->color)->toBe(OptionColor::Red->value)
        ->and($this->stageField->refresh()->settings->enable_option_colors)->toBeTrue();
});

it('rings the current stage color in the swatch grid', function (): void {
    $id = stageIds($this->stageField)[1];

    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnColor', $id, OptionColor::Pink->value);

    $pink = OptionColor::Pink->value;

    expect(stageMenuOf($component, $id))->toMatch("/data-swatch=\"{$pink}\"[^>]*aria-checked=\"true\"|aria-checked=\"true\"[^>]*data-swatch=\"{$pink}\"/");
});

it('writes nothing when the picked color is the stored color in another case', function (): void {
    $id = stageIds($this->stageField)[1];
    $settings = $this->stageField->settings;
    $settings->enable_option_colors = true;
    $this->stageField->update(['settings' => $settings]);
    stageOption($id)->update(['settings' => ['color' => strtoupper(OptionColor::Red->value)]]);

    livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnColor', $id, OptionColor::Red->value)
        ->assertHasNoErrors();

    expect(stageOption($id)->settings->color)->toBe(strtoupper(OptionColor::Red->value));
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

it('turns confetti on and off for a stage and keeps it for the whole workspace', function (): void {
    $id = stageIds($this->stageField)[1];

    $component = livewire(OpportunitiesBoard::class);

    expect(stageOption($id)->settings->celebrate)->toBeNull()
        ->and($component->html())->toContain('data-board-celebrate="[]"')
        ->and(stageMenuOf($component, $id))->toContain('data-stage-confetti="'.$id.'"')->toContain('data-visible="false"');

    $component->call('setBoardColumnCelebrate', $id, true)->assertHasNoErrors();

    expect(stageOption($id)->settings->celebrate)->toBeTrue()
        ->and($component->html())->toContain('data-board-celebrate="'.e(json_encode([$id])).'"')
        ->and(stageMenuOf($component, $id))->toContain('data-visible="true"')
        ->and(stageMenuOf(livewire(OpportunitiesBoard::class), $id))->toContain('data-visible="true"');

    $component->call('setBoardColumnCelebrate', $id, false);

    expect(stageOption($id)->settings->celebrate)->toBeFalse()
        ->and($component->html())->toContain('data-board-celebrate="[]"');
});

it('keeps confetti through a rename and a recolor, and the color through toggling it', function (): void {
    $id = stageIds($this->stageField)[1];

    $component = livewire(OpportunitiesBoard::class)
        ->call('setBoardColumnColor', $id, OptionColor::Red->value)
        ->call('setBoardColumnCelebrate', $id, true);

    expect(stageOption($id)->settings->color)->toBe(OptionColor::Red->value);

    $component->call('renameBoardColumn', $id, 'Renamed stage')->call('setBoardColumnColor', $id, OptionColor::Blue->value);

    expect(stageOption($id)->name)->toBe('Renamed stage')
        ->and(stageOption($id)->settings->color)->toBe(OptionColor::Blue->value)
        ->and(stageOption($id)->settings->celebrate)->toBeTrue();
});

it('keeps confetti when a caller saves the package settings object over it', function (): void {
    $id = stageIds($this->stageField)[1];

    livewire(OpportunitiesBoard::class)->call('setBoardColumnCelebrate', $id, true);

    stageOption($id)->update(['settings' => new CustomFieldOptionSettingsData(color: OptionColor::Green->value)]);

    expect(stageOption($id)->settings->color)->toBe(OptionColor::Green->value)
        ->and(stageOption($id)->settings->celebrate)->toBeTrue();
});

it('writes nothing when confetti already has the asked state', function (): void {
    $id = stageIds($this->stageField)[1];

    livewire(OpportunitiesBoard::class)->call('setBoardColumnCelebrate', $id, false);

    expect(Activity::query()->where('subject_type', (new CustomFieldOption)->getMorphClass())->count())->toBe(0);
});

it('refuses a forged confetti toggle from a member and from a viewer and shows them no row', function (WorkspaceRole $role): void {
    $id = stageIds($this->stageField)[1];
    $this->actingAs(makeBoardMember($this, $role));

    $component = livewire(OpportunitiesBoard::class);

    expect(stageMenuOf($component, $id))->not->toContain('data-stage-confetti');

    $component->call('setBoardColumnCelebrate', $id, true)->assertForbidden();

    expect(stageOption($id)->settings->celebrate)->toBeNull();
})->with([WorkspaceRole::Member, WorkspaceRole::Viewer]);

it('gives the no-value column no confetti row and ignores a toggle for it', function (): void {
    $component = livewire(OpportunitiesBoard::class)->call('setBoardColumnVisible', BoardStateData::NO_VALUE_COLUMN, true);

    expect(stageMenuOf($component, BoardStateData::NO_VALUE_COLUMN))->not->toContain('data-stage-confetti');

    $component->call('setBoardColumnCelebrate', BoardStateData::NO_VALUE_COLUMN, true)->assertHasNoErrors();

    expect($component->html())->toContain('data-board-celebrate="[]"');
});

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

    expect($menu)->toContain('data-swatch="'.OptionColor::Gray->value.'"');

    $ringed = preg_match_all('/data-swatch="[^"]*"[^>]*aria-checked="true"|aria-checked="true"[^>]*data-swatch="/', $menu);

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
        if ($armed && str_contains($query->sql, 'for no key update') && str_contains($query->sql, 'custom_fields')) {
            $armed = false;
            DB::table('custom_field_options')->where('id', $other)->update(['name' => 'Raced']);
        }
    });

    $component = livewire(OpportunitiesBoard::class);

    $changed = __('filament/pages/boards.stage_menu.changed', ['noun' => 'stage']);

    match ($write) {
        'delete' => $component->callAction(deleteStageAction($ids[1]))->assertNotified($changed),
        'rename' => $component->call('renameBoardColumn', $ids[1], 'Discovery')->assertHasErrors(['boardStageMenu.'.$ids[1] => $changed]),
        'color' => $component->call('setBoardColumnColor', $ids[1], OptionColor::Red->value)->assertHasErrors(['boardStageMenu.'.$ids[1] => $changed]),
    };

    expect(stageOption($ids[1])->name)->not->toBe('Discovery')->and(CustomFieldOption::query()->withoutGlobalScopes()->find($ids[1]))->not->toBeNull();
})->with(['delete', 'rename', 'color']);

it('refuses a rename made from a name another admin has since changed', function (): void {
    $id = stageIds($this->stageField)[1];
    $seen = stageOption($id)->name;
    CustomFieldOption::query()->withoutGlobalScopes()->whereKey($id)->update(['name' => 'Renamed elsewhere']);

    livewire(OpportunitiesBoard::class)
        ->call('renameBoardColumn', $id, 'Discovery', $seen)
        ->assertHasErrors(['boardStageMenu.'.$id => __('filament/pages/boards.stage_menu.changed', ['noun' => 'stage'])]);

    expect(stageOption($id)->name)->toBe('Renamed elsewhere');
});

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
        if ($armed && str_contains($query->sql, 'for no key update') && str_contains($query->sql, 'custom_fields')) {
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
