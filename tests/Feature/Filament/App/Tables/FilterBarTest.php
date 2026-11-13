<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterCondition;
use App\Enums\WorkspaceRole;
use App\Filament\Concerns\HasCustomFieldColumns;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\HasFilterBar;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Filament\Tables\FilterBar\FilterRuleCompiler;
use App\Filament\Tables\FilterBar\FilterRules;
use App\Filament\Tables\FilterBar\SortList;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\SavedView;
use App\Models\Task;
use App\Models\User;
use App\Queries\Filters\LogicFilter;
use App\Queries\Filters\StaleDaysFilter;
use App\Queries\Filters\TreeFilter;
use App\Support\IconPath;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\IconSize;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Once;
use Relaticle\CustomFields\Data\CustomFieldOptionSettingsData;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Tests\Helpers\WorkspaceCustomField;

use function Filament\Support\generate_icon_html;

mutates(
    ConditionsFilter::class,
    FilterCondition::class,
    FilterFieldCatalog::class,
    FilterRuleCompiler::class,
    FilterRules::class,
    HasCustomFieldColumns::class,
    HasFilterBar::class,
    LogicFilter::class,
    PersistsTableState::class,
    SortList::class,
    TreeFilter::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    $this->actingAs($this->user);
    Filament::setTenant($this->workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();

    openOpportunitiesOnTheList($this->user, $this->workspace);
});

it('reads a saved view that includes deleted records as unchanged and stores the mode when it switches', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(filters: ['trashed' => ['value' => 1]]),
    ]);
    $updatedAt = $view->updated_at;
    $this->travelTo(now()->addHour());

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());

    expect($component->get('tableFilters.trashed.value'))->toBe(1);

    $component->call('$refresh');

    expect($view->refresh()->updated_at)->toEqual($updatedAt);

    $component->call('setDeletedRecordsFilter', 'only');

    expect($view->refresh()->state->filters['trashed']['value'])->toBe(0);

    $component->call('setDeletedRecordsFilter', 'included');

    expect($view->refresh()->state->filters['trashed']['value'])->toBe(1);
});

it('lists neither the live nor the deleted record when a negated name rule matches both', function (string $page, string $model): void {
    [$live, $deleted] = filterBarDeletedPair($this->user, $model);
    $field = CrmEntity::tryFromModel($live)->titleColumn();

    livewire($page)
        ->call('setDeletedRecordsFilter', 'included')
        ->call('setFilterRules', [['field' => $field, 'condition' => 'does_not_contain', 'value' => 'Acme']])
        ->assertCanNotSeeTableRecords([$live, $deleted]);
})->with('filter bar pages');

it('applies a positive rule to the deleted view', function (string $page, string $model): void {
    [$live, $deleted] = filterBarDeletedPair($this->user, $model);
    $other = tap(filterBarRecord($this->user, $model, 'Globex'))->delete();
    $rules = [['field' => CrmEntity::tryFromModel($live)->titleColumn(), 'condition' => 'contains', 'value' => 'Acme']];

    livewire($page)
        ->call('setFilterRules', $rules)
        ->call('setDeletedRecordsFilter', 'only')
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$live, $other])
        ->call('setDeletedRecordsFilter', 'included')
        ->assertCanSeeTableRecords([$live, $deleted])
        ->assertCanNotSeeTableRecords([$other]);
})->with('filter bar pages');

it('applies a negated rule to the deleted view and to the live and deleted view', function (string $page, string $model): void {
    [$live, $deleted] = filterBarDeletedPair($this->user, $model);
    $liveOther = filterBarRecord($this->user, $model, 'Globex');
    $deletedOther = tap(filterBarRecord($this->user, $model, 'Globex'))->delete();
    $rules = [['field' => CrmEntity::tryFromModel($live)->titleColumn(), 'condition' => 'does_not_contain', 'value' => 'Acme']];

    livewire($page)
        ->call('setFilterRules', $rules)
        ->call('setDeletedRecordsFilter', 'only')
        ->assertCanSeeTableRecords([$deletedOther])
        ->assertCanNotSeeTableRecords([$live, $deleted, $liveOther])
        ->call('setDeletedRecordsFilter', 'included')
        ->assertCanSeeTableRecords([$liveOther, $deletedOther])
        ->assertCanNotSeeTableRecords([$live, $deleted])
        ->call('setDeletedRecordsFilter', null)
        ->assertCanSeeTableRecords([$liveOther])
        ->assertCanNotSeeTableRecords([$live, $deleted, $deletedOther]);
})->with('filter bar pages');

it('keeps a deleted record that is not checked and drops one that is, when deleted records are included', function (): void {
    [, , , $markedLive] = filterBarScenario('icp', $this->user);
    $marked = filterBarRecord($this->user, Company::class, 'Marked gone', values: ['icp' => true]);
    $unmarked = filterBarRecord($this->user, Company::class, 'Unmarked gone', values: ['icp' => false]);
    $never = filterBarRecord($this->user, Company::class, 'Never gone');
    collect([$marked, $unmarked, $never])->each->delete();

    livewire(ListCompanies::class)
        ->call('setFilterRules', [['field' => 'custom_fields.icp', 'condition' => 'is_not_checked']])
        ->call('setDeletedRecordsFilter', 'included')
        ->assertCanSeeTableRecords([$markedLive[1], $markedLive[2], $unmarked, $never])
        ->assertCanNotSeeTableRecords([$markedLive[0], $marked]);
});

it('keeps a deleted record whose number differs and drops one that equals it, when deleted records are included', function (): void {
    $equal = filterBarRecord($this->user, Opportunity::class, 'Equal gone', values: ['amount' => 1000]);
    $other = filterBarRecord($this->user, Opportunity::class, 'Other gone', values: ['amount' => 100]);
    $blank = filterBarRecord($this->user, Opportunity::class, 'Blank gone');
    $liveEqual = filterBarRecord($this->user, Opportunity::class, 'Equal live', values: ['amount' => 1000]);
    $liveOther = filterBarRecord($this->user, Opportunity::class, 'Other live', values: ['amount' => 5000]);
    collect([$equal, $other, $blank])->each->delete();

    livewire(ListOpportunities::class)
        ->call('setFilterRules', [['field' => 'custom_fields.amount', 'condition' => 'is_not', 'value' => 1000]])
        ->call('setDeletedRecordsFilter', 'included')
        ->assertCanSeeTableRecords([$other, $blank, $liveOther])
        ->assertCanNotSeeTableRecords([$equal, $liveEqual]);
});

it('hands the chip bar every field with its conditions', function (): void {
    $fields = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['fields'])->keyBy('key');

    expect($fields['name'])
        ->toMatchArray(['label' => 'Company', 'kind' => 'text'])
        ->and(array_column($fields['name']['conditions'], 'value'))->toBe(['contains', 'does_not_contain', 'starts_with', 'ends_with', 'is', 'is_not', 'is_empty', 'is_not_empty'])
        ->and($fields['name']['conditions'][0])->toBe(['value' => 'contains', 'label' => 'contains', 'takesValue' => true, 'shape' => 'text'])
        ->and($fields['name']['icon'])->not->toBeEmpty();
});

it('hands the chip bar a choice field options and offers the viewer as the only member option', function (): void {
    filterBarField($this->user, 'opportunity', 'stage_pick', 'select', ['Won', 'Lost']);

    $fields = collect(livewire(ListOpportunities::class)->instance()->getFilterBarData()['fields'])->keyBy('key');

    expect(array_column($fields['custom_fields.stage_pick']['options'], 'label'))->toBe(['Won', 'Lost'])
        ->and(array_column($fields['custom_fields.stage_pick']['options'], 'color'))->toBe([null, null])
        ->and($fields['creator']['options'])->toBe([[
            'id' => '@me',
            'label' => "{$this->user->name} (me)",
            'image' => $this->user->getFilamentAvatarUrl(),
            'icon' => null,
            'circular' => true,
            'color' => null,
        ]])
        ->and($fields['company']['options'])->toBe([]);
});

it('hands the chip bar a tags field known tags as the text a rule stores', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    filterBarRecord($this->user, Company::class, 'Vip', values: ['labels' => ['vip', 'lead']]);

    $fields = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['fields'])->keyBy('key');

    expect(array_column($fields['custom_fields.labels']['options'], 'id'))->toEqualCanonicalizing(['vip', 'lead'])
        ->and(array_column($fields['custom_fields.labels']['options'], 'label'))->toEqualCanonicalizing(['vip', 'lead']);
});

it('hands the chip bar option colours as real colours only when the field shows them', function (): void {
    filterBarField($this->user, 'opportunity', 'stage_pick', 'select', ['Won', 'Lost']);
    $field = WorkspaceCustomField::byCode($this->user->currentWorkspace->getKey(), 'opportunity', 'stage_pick');
    $field->options->each(fn ($option) => $option->update(['settings' => new CustomFieldOptionSettingsData(color: '#ff0000')]));
    $colors = fn (): array => array_column(collect(livewire(ListOpportunities::class)->instance()->getFilterBarData()['fields'])->keyBy('key')['custom_fields.stage_pick']['options'], 'color');

    expect($colors())->toBe([null, null]);

    $field->update(['settings' => new CustomFieldSettingsData(enable_option_colors: true)]);
    Once::flush();

    expect($colors())->toBe(['#ff0000', '#ff0000']);
});

it('resolves a named colour of a native option to the colour the theme draws', function (): void {
    $options = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['fields'])->keyBy('key')['creation_source']['options'];
    $colors = array_column($options, 'color', 'id');

    expect($colors['web'])->toBe(FilamentColor::getColors()['info'][500])
        ->and($colors['api'])->toBe(Color::Purple[500])
        ->and($colors['chat'])->toBe(Color::Indigo[500])
        ->and(array_filter($colors, is_null(...)))->toBe([]);
});

it('offers every tag once whatever its case, including one whose option is gone', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    $vip = filterBarRecord($this->user, Company::class, 'Vip', values: ['labels' => ['VIP', 'old']]);
    filterBarRecord($this->user, Company::class, 'Vip lower', values: ['labels' => ['vip']]);
    filterBarRecord($this->user, Company::class, 'Plain');
    WorkspaceCustomField::byCode($this->user->currentWorkspace->getKey(), 'company', 'labels')->options()->where('name', 'old')->delete();

    $options = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['fields'])->keyBy('key')['custom_fields.labels']['options'];

    expect(array_column($options, 'id'))->toEqualCanonicalizing(['VIP', 'old'])
        ->and(livewire(ListCompanies::class)
            ->call('setFilterRules', [['field' => 'custom_fields.labels', 'condition' => 'has_any_of', 'value' => ['old']]])
            ->assertCanSeeTableRecords([$vip])->assertCountTableRecords(1))->not->toBeNull();
});

it('offers no tag that only a deleted record holds', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    filterBarRecord($this->user, Company::class, 'Live', values: ['labels' => ['kept', 'shared']]);
    filterBarRecord($this->user, Company::class, 'Gone', values: ['labels' => ['dropped', 'shared']])->delete();
    WorkspaceCustomField::byCode($this->user->currentWorkspace->getKey(), 'company', 'labels')->options()->delete();

    $options = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['fields'])->keyBy('key')['custom_fields.labels']['options'];

    expect(array_column($options, 'id'))->toEqualCanonicalizing(['kept', 'shared']);
});

it('narrows the list by a tag whatever the case it was stored in', function (): void {
    filterBarField($this->user, 'company', 'labels', CustomFieldType::TAGS_INPUT->value);
    $upper = filterBarRecord($this->user, Company::class, 'Upper', values: ['labels' => ['VIP']]);
    $lower = filterBarRecord($this->user, Company::class, 'Lower', values: ['labels' => ['vip']]);
    $other = filterBarRecord($this->user, Company::class, 'Other', values: ['labels' => ['lead']]);

    livewire(ListCompanies::class)
        ->call('setFilterRules', [['field' => 'custom_fields.labels', 'condition' => 'has_any_of', 'value' => ['VIP']]])
        ->assertCanSeeTableRecords([$upper, $lower])
        ->assertCanNotSeeTableRecords([$other])
        ->call('setFilterRules', [['field' => 'custom_fields.labels', 'condition' => 'has_none_of', 'value' => ['vip']]])
        ->assertCanSeeTableRecords([$other])
        ->assertCanNotSeeTableRecords([$upper, $lower]);
});

it('hands the chip bar a label for every stored id, naming a deleted record', function (): void {
    $companies = collect(['Aa', 'Bb', 'Cc'])->map(fn (string $name): Model => filterBarRecord($this->user, Company::class, $name));
    $companies->last()->delete();

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [
            ['field' => 'company', 'condition' => 'is_any_of', 'value' => $companies->map->getKey()->all()],
            ['field' => 'creator', 'condition' => 'is_any_of', 'value' => ['@me', (string) $this->user->getKey()]],
            ['field' => 'name', 'condition' => 'contains', 'value' => 'x'],
        ])
        ->instance()->getFilterBarData()['rules'];

    expect(array_column($shown[0]['labels'], 'label'))->toBe(['Aa', 'Bb', 'Cc (deleted)'])
        ->and(array_column($shown[0]['labels'], 'id'))->toBe($companies->map(fn (Model $company): string => (string) $company->getKey())->all())
        ->and(array_column($shown[1]['labels'], 'label'))->toBe(["{$this->user->name} (me)"])
        ->and($shown[1]['display'])->toBe("{$this->user->name} (me)")
        ->and($shown[1]['labels'][0]['image'])->toBe($this->user->getFilamentAvatarUrl())
        ->and($shown[0]['labels'][0])->toMatchArray(['image' => null, 'icon' => CrmEntity::Company->iconPath(), 'circular' => false])
        ->and($shown[2]['labels'])->toBe([]);
});

it('carries the mark of every stored record in the rule labels and gives a deleted company the icon', function (): void {
    $logo = filterBarRecord($this->user, Company::class, 'Logo Co');
    $logo->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    $gone = filterBarRecord($this->user, Company::class, 'Gone Co');
    $gone->addMediaFromString(onePixelPng())->usingFileName('gone.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);
    $gone->delete();
    $colleague = filterBarMember($this->user);

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [
            ['field' => 'company', 'condition' => 'is_any_of', 'value' => [$logo->getKey(), $gone->getKey()]],
            ['field' => 'creator', 'condition' => 'is_any_of', 'value' => [$colleague->getKey()]],
        ])
        ->instance()->getFilterBarData()['rules'];

    expect($shown[0]['labels'][0])->toMatchArray(['image' => $logo->fresh()->logo, 'icon' => CrmEntity::Company->iconPath(), 'circular' => false])
        ->and($shown[0]['labels'][1])->toMatchArray(['label' => 'Gone Co (deleted)', 'image' => null, 'icon' => CrmEntity::Company->iconPath()])
        ->and($shown[1]['labels'][0])->toMatchArray(['image' => $colleague->getFilamentAvatarUrl(), 'icon' => null, 'circular' => true]);
});

it('reads a rule as the chip shows it', function (): void {
    $rules = [
        ['field' => 'name', 'condition' => 'contains', 'value' => 'acme'],
        ['field' => 'creator', 'condition' => 'is_any_of', 'value' => ['@me']],
        ['field' => 'name', 'condition' => 'is_empty'],
    ];

    $shown = livewire(ListCompanies::class)->call('setFilterRules', $rules)->instance()->getFilterBarData()['rules'];

    expect(array_column($shown, 'display'))->toBe(['acme', "{$this->user->name} (me)", '']);
});

it('reads Me as the name of whoever opens a shared view', function (): void {
    $colleague = filterBarMember($this->user);
    $colleague->update(['name' => 'Casey Colleague']);
    $rules = [['field' => 'creator', 'condition' => 'is_any_of', 'value' => ['@me']]];
    $display = fn (): string => livewire(ListCompanies::class)->call('setFilterRules', $rules)->instance()->getFilterBarData()['rules'][0]['display'];

    expect($display())->toBe("{$this->user->name} (me)");

    $this->actingAs($colleague);

    expect($display())->toBe('Casey Colleague (me)');
});

it('shows the viewer once, as me, whichever way a rule stores them', function (): void {
    expect(livewire(ListCompanies::class)->instance()->getFilterBarData()['viewerId'])->toBe((string) $this->user->getKey());

    $viewer = (string) $this->user->getKey();
    $rules = fn (array $value): array => [['field' => 'creator', 'condition' => 'is_any_of', 'value' => $value]];
    $shown = fn (array $value): array => livewire(ListCompanies::class)->call('setFilterRules', $rules($value))->instance()->getFilterBarData()['rules'][0];

    expect($shown(['@me', $viewer]))->toMatchArray(['display' => "{$this->user->name} (me)"])
        ->and(array_column($shown(['@me', $viewer])['labels'], 'id'))->toBe(['@me'])
        ->and($shown([$viewer])['display'])->toBe("{$this->user->name} (me)")
        ->and($shown([$viewer])['labels'][0])->toMatchArray(['id' => $viewer, 'label' => "{$this->user->name} (me)", 'image' => $this->user->getFilamentAvatarUrl()]);
});

it('names a deleted company in a rule instead of showing a blank or an id', function (): void {
    $live = filterBarRecord($this->user, Company::class, 'Initech');
    $gone = filterBarRecord($this->user, Company::class, 'Globex');
    $gone->delete();

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [['field' => 'company', 'condition' => 'is_any_of', 'value' => [$gone->getKey(), $live->getKey()]]])
        ->instance()->getFilterBarData()['rules'];

    expect($shown)->toHaveCount(1)
        ->and($shown[0]['display'])->toBe('Globex (deleted), Initech');
});

it('summarises a long list of values with a count of the rest', function (): void {
    $companies = collect(['Aa', 'Bb', 'Cc', 'Dd'])->map(fn (string $name): Model => filterBarRecord($this->user, Company::class, $name));

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [['field' => 'company', 'condition' => 'is_any_of', 'value' => $companies->map->getKey()->all()]])
        ->instance()->getFilterBarData()['rules'];

    expect($shown[0]['display'])->toBe('Aa, Bb +2');
});

it('writes a currency value with its symbol and decimals and a plain number with grouping, and keeps the stored value raw', function (): void {
    resolve(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Budget',
        'code' => 'budget',
        'type' => 'currency',
        'settings' => ['currency_code' => 'USD', 'display_type' => 'symbol', 'decimal_places' => 0],
    ]);
    filterBarField($this->user, 'opportunity', 'seats', 'number');
    resolve(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Fee',
        'code' => 'fee',
        'type' => 'currency',
        'settings' => ['currency_code' => 'EUR', 'display_type' => 'symbol', 'decimal_places' => 2],
    ]);

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [
            ['field' => 'custom_fields.budget', 'condition' => 'greater_than', 'value' => 20000],
            ['field' => 'custom_fields.seats', 'condition' => 'greater_than', 'value' => 10000],
            ['field' => 'custom_fields.fee', 'condition' => 'less_than', 'value' => 1234.5],
        ])
        ->instance()->getFilterBarData()['rules'];

    expect(array_column($shown, 'display'))->toBe(['$20,000', '10,000', '€1,234.50'])
        ->and(array_column($shown, 'value'))->toBe([20000, 10000, 1234.5]);
});

it('writes the relative period of a date rule in words', function (): void {
    filterBarField($this->user, 'opportunity', 'closes', 'date');

    $shown = livewire(ListOpportunities::class)
        ->call('setFilterRules', [
            ['field' => 'custom_fields.closes', 'condition' => 'in_the_next', 'value' => ['amount' => 30, 'unit' => 'days']],
            ['field' => 'custom_fields.closes', 'condition' => 'this', 'value' => ['unit' => 'months']],
            ['field' => 'custom_fields.closes', 'condition' => 'is', 'value' => '@today'],
        ])
        ->instance()->getFilterBarData()['rules'];

    expect(array_column($shown, 'display'))->toBe(['30 days', 'month', 'today']);
});

it('tells the empty list which rules hide its records and clears them from a button', function (string $page, string $model, string $records): void {
    $model::factory()->recycle([$this->user, $this->workspace])->create();
    $column = $model === Task::class || $model === Note::class ? 'title' : 'name';

    livewire($page)
        ->assertDontSee("No {$records} match this view")
        ->call('setFilterRules', [['field' => $column, 'condition' => 'contains', 'value' => 'zzqx-no-match']])
        ->assertSee("No {$records} match this view")
        ->assertSee('Clear filters')
        ->callAction(TestAction::make('clearFilterBar')->table())
        ->assertDontSee("No {$records} match this view")
        ->assertSet('tableFilters.conditions.rules', []);
})->with([
    'companies' => [ListCompanies::class, Company::class, 'companies'],
    'people' => [ListPeople::class, People::class, 'people'],
    'opportunities' => [ListOpportunities::class, Opportunity::class, 'opportunities'],
    'tasks' => [ManageTasks::class, Task::class, 'tasks'],
    'notes' => [ManageNotes::class, Note::class, 'notes'],
]);

it('reads a saved number rule as unchanged when the number comes back as text', function (int|float $saved, string $written): void {
    filterBarScenario('amounts', $this->user);
    $rule = fn (mixed $value): array => ['match' => 'all', 'rules' => [['field' => 'custom_fields.amount', 'condition' => 'greater_than', 'value' => $value]]];
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create([
        'entity' => CrmEntity::Opportunity,
        'state' => new TableStateData(filters: ['conditions' => $rule($saved)]),
    ]);

    $updatedAt = $view->updated_at;
    $this->travelTo(now()->addHour());

    $component = livewire(ListOpportunities::class)->call('applySavedView', $view->getKey());
    $component->set('tableFilters.conditions', $rule($written));

    expect($view->refresh()->updated_at)->toEqual($updatedAt)
        ->and($component->get('tableFilters.conditions.rules.0.value'))->toBe($saved);
})->with([
    'whole' => [1000, '1000'],
    'decimal' => [1000.5, '1000.5'],
    'negative' => [-5, '-5'],
]);

it('sets the sort from the filter bar the way a header click does', function (string $page): void {
    $component = livewire($page);
    $column = collect($component->instance()->getTable()->getVisibleColumns())->first(fn ($column): bool => $column->isSortable())->getName();

    $component->call('setFilterBarSorts', [['column' => $column, 'direction' => 'desc']])
        ->assertSet('tableSort', "{$column}:desc");

    expect($component->instance()->getFilterBarData()['sorts'])->toHaveCount(1)
        ->and($component->instance()->getFilterBarData()['sorts'][0])->toMatchArray(['column' => $column, 'direction' => 'desc']);

    $component->call('setFilterBarSorts', [['column' => $column, 'direction' => 'asc']])->assertSet('tableSort', "{$column}:asc")
        ->call('setFilterBarSorts', [])->assertSet('tableSort', null)->assertSet('tableThenSorts', null);

    expect($component->instance()->getFilterBarData()['sorts'])->toBe([]);
})->with('filter bar pages');

it('ignores a sort the table does not hold or cannot sort', function (string $page): void {
    $component = livewire($page);
    $plain = collect($component->instance()->getTable()->getVisibleColumns())->first(fn ($column): bool => ! $column->isSortable());
    $sortable = collect($component->instance()->getTable()->getVisibleColumns())->first(fn ($column): bool => $column->isSortable())->getName();

    $rejected = array_map(fn (string $name): array => ['column' => $name, 'direction' => 'desc'], array_filter(['no_such_column', 'id', '', $plain?->getName()]));

    $component->call('setFilterBarSorts', [...$rejected, ['column' => $sortable, 'direction' => 'sideways'], ['column' => $sortable], ['column' => $sortable, 'direction' => 'desc'], 'name', null])
        ->assertSet('tableSort', "{$sortable}:asc")
        ->assertSet('tableThenSorts', null);
})->with('filter bar pages');

it('offers the sort control only columns the member can see and sort', function (): void {
    $component = livewire(ListCompanies::class);
    $sortable = array_column($component->instance()->getFilterBarData()['sortable'], 'key');

    expect($sortable)->toContain('name')->and($sortable)->not->toContain('no_such_column');

    $component->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'asc']]);
    $component->call('sortTable', 'no_such_column', 'asc');

    expect($component->instance()->getFilterBarData()['sorts'])->toBe([]);
});

it('lists the groups a table declares and writes the grouping a toolbar change writes', function (): void {
    $component = livewire(ManageTasks::class);
    $data = $component->instance()->getFilterBarData();

    expect(array_column($data['groups'], 'id'))->toBe(['status_group', 'priority_group'])
        ->and($data['grouping'])->toBeNull();

    $component->call('setFilterBarGrouping', 'priority_group', 'desc')->assertSet('tableGrouping', 'priority_group:desc');

    expect($component->instance()->getFilterBarData()['grouping'])->toMatchArray(['id' => 'priority_group', 'direction' => 'desc']);

    $component->call('setFilterBarGrouping', 'nonsense', 'asc')->assertSet('tableGrouping', 'priority_group:desc')
        ->call('setFilterBarGrouping', 'status_group', 'sideways')->assertSet('tableGrouping', 'priority_group:desc')
        ->call('setFilterBarGrouping', null, null)->assertSet('tableGrouping', null);
});

it('declares no groups on the lists that group nothing', function (string $page): void {
    expect(livewire($page)->instance()->getFilterBarData()['groups'])->toBe([]);
})->with([
    'companies' => [ListCompanies::class],
    'people' => [ListPeople::class],
    'opportunities' => [ListOpportunities::class],
    'notes' => [ManageNotes::class],
]);

it('draws no grouping settings of its own on any list', function (string $page): void {
    expect(livewire($page)->html())->not->toContain('fi-ta-grouping-settings');
})->with('filter bar pages');

it('offers deleted records to every signed-in member, a viewer too', function (string $page): void {
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->getKey()]);
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);

    expect(openList($page)->instance()->getFilterBarData()['deletable'])->toBeTrue();

    $this->actingAs($viewer);

    expect(openList($page)->instance()->getFilterBarData()['deletable'])->toBeTrue();
})->with('filter bar pages');

it('offers deleted records to nobody once the member is signed out', function (): void {
    $component = livewire(ListCompanies::class);

    Filament::auth()->logout();

    expect($component->instance()->getFilterBarData()['deletable'])->toBeFalse();
});

it('stores a grouping set from the group control on the view and reads the view as unchanged on return', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->workspace)->create(['entity' => CrmEntity::Task, 'state' => new TableStateData]);

    $component = livewire(ManageTasks::class)->call('applySavedView', $view->getKey());

    expect($view->refresh()->state->grouping)->toBeNull();

    $component->call('setFilterBarGrouping', 'status_group', 'desc');

    expect($view->refresh()->state->grouping)->toBe('status_group:desc');

    $revisit = livewire(ManageTasks::class)->call('applySavedView', $view->getKey());

    expect($revisit->get('tableGrouping'))->toBe('status_group:desc');
});

it('hands the sort and group controls an icon for each column that has one', function (): void {
    $sortable = collect(livewire(ListCompanies::class)->instance()->getFilterBarData()['sortable'])->keyBy('key');

    expect($sortable['name']['icon'])->not->toBeEmpty()
        ->and(collect(livewire(ManageTasks::class)->instance()->getFilterBarData()['groups'])->keyBy('id')['status_group']['icon'])->not->toBeEmpty();
});

it('draws every custom field icon from the app own outline set', function (): void {
    filterBarField($this->user, 'opportunity', 'budget', 'currency');
    filterBarField($this->user, 'opportunity', 'pick', 'select', ['Won', 'Lost']);
    filterBarField($this->user, 'opportunity', 'due', 'date');

    $data = livewire(ListOpportunities::class)->instance()->getFilterBarData();
    $fields = collect($data['fields'])->keyBy('key');

    expect($fields['custom_fields.budget']['icon'])->toBe(IconPath::for(CustomFieldType::CURRENCY->icon()))
        ->and($fields['custom_fields.pick']['icon'])->toBe(IconPath::for(CustomFieldType::SELECT->icon()))
        ->and($fields['custom_fields.due']['icon'])->toBe(IconPath::for(CustomFieldType::DATE->icon()))
        ->and(collect($data['sortable'])->keyBy('key')['custom_fields.budget']['icon'])->toBe(IconPath::for(CustomFieldType::CURRENCY->icon()));
});

it('hands the chip bar the period units with their limits ', function (): void {
    $data = livewire(ListOpportunities::class)->instance()->getFilterBarData();
    $units = collect($data['periodUnits'])->keyBy('value');

    expect(array_keys($units->all()))->toBe(['days', 'weeks', 'months', 'quarters', 'years'])
        ->and($units['days'])->toMatchArray(['label' => 'days', 'singular' => 'day', 'max' => 3650])
        ->and($units['years']['max'])->toBe(100)
        ->and(collect($data['fields'])->firstWhere('key', 'stale_days')['limit'])->toBe(StaleDaysFilter::MAX_DAYS);
});

it('ignores a sort written straight from the browser and keeps the one a header click set', function (): void {
    livewire(ListCompanies::class)
        ->call('sortTable', 'name', 'desc')
        ->set('tableSort', 'name:asc')
        ->assertSet('tableSort', 'name:desc')
        ->call('setFilterBarSorts', [['column' => 'name', 'direction' => 'asc']])
        ->assertSet('tableSort', 'name:asc');
});

it('orders by each sort in turn and breaks ties with the next one', function (): void {
    $older = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme', 'created_at' => now()->subDays(3)]);
    $newer = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme', 'created_at' => now()->subDay()]);
    $other = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Beta', 'created_at' => now()->subDays(5)]);

    $component = livewire(ListCompanies::class);
    $order = fn (array $sorts): array => $component->call('setFilterBarSorts', $sorts)->instance()->getTableRecords()->modelKeys();

    expect($order([['column' => 'name', 'direction' => 'asc'], ['column' => 'created_at', 'direction' => 'asc']]))->toBe([$older->getKey(), $newer->getKey(), $other->getKey()])
        ->and($order([['column' => 'name', 'direction' => 'asc'], ['column' => 'created_at', 'direction' => 'desc']]))->toBe([$newer->getKey(), $older->getKey(), $other->getKey()])
        ->and($order([['column' => 'created_at', 'direction' => 'asc'], ['column' => 'name', 'direction' => 'asc']]))->toBe([$other->getKey(), $older->getKey(), $newer->getKey()]);
});

it('sorts by every sortable column at once, relations and custom fields included', function (string $page, string $model): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();
    $component = livewire($page);
    $sortable = array_column($component->instance()->getFilterBarData()['sortable'], 'key');

    $component->call('setFilterBarSorts', array_map(fn (string $column): array => ['column' => $column, 'direction' => 'desc'], $sortable))
        ->assertCanSeeTableRecords([$record]);

    expect(array_column($component->instance()->getFilterBarData()['sorts'], 'column'))->toBe($sortable);
})->with('filter bar pages');

it('labels a renamed custom attribute the same in the header, the sort picker and the filter picker', function (): void {
    filterBarField($this->user, 'company', 'fit_score', CustomFieldType::NUMBER->value);

    $component = livewire(ListCompanies::class)
        ->callAction('editTableColumnLabel', data: ['label' => 'Ideal customer fit'], arguments: ['column' => 'custom_fields.fit_score'])
        ->assertHasNoActionErrors();

    $data = $component->instance()->getFilterBarData();
    $filterLabel = collect($data['fields'])->firstWhere('key', 'custom_fields.fit_score')['label'];
    $sortLabel = collect($data['sortable'])->firstWhere('key', 'custom_fields.fit_score')['label'];

    expect($filterLabel)->toBe('Ideal customer fit')
        ->and($sortLabel)->toBe('Ideal customer fit');
});

it('draws the column header of a custom attribute with the icon the filter picker gives it', function (string $type, array $options): void {
    filterBarField($this->user, 'company', 'header_icon', $type, $options);

    $component = livewire(ListCompanies::class);
    $icon = collect($component->instance()->filterFieldCatalog())->get('custom_fields.header_icon')->icon;
    $header = (string) $component->instance()->getTable()->getColumn('custom_fields.header_icon')->getLabel();

    expect($header)->toContain(generate_icon_html($icon, size: IconSize::Small)->toHtml());
})->with([
    'a toggle' => [CustomFieldType::TOGGLE->value, []],
    'a select' => [CustomFieldType::SELECT->value, ['One', 'Two']],
]);
