<?php

declare(strict_types=1);

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\HasColumnMenu;
use App\Filament\Tables\ResizableColumns;
use App\Models\TableLayout;
use App\Models\TablePreference;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;

mutates(HasColumnMenu::class, ResizableColumns::class);

dataset('column menu pages', [
    'companies' => [ListCompanies::class, CrmEntity::Company],
    'people' => [ListPeople::class, CrmEntity::People],
    'opportunities' => [ListOpportunities::class, CrmEntity::Opportunity],
    'tasks' => [ManageTasks::class, CrmEntity::Task],
    'notes' => [ManageNotes::class, CrmEntity::Note],
]);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

function columnMenuPreferences(): int
{
    return TablePreference::query()->withoutGlobalScopes()->count();
}

/**
 * @return list<string>
 */
function columnOrder(Testable $component): array
{
    return array_column($component->get('tableColumns'), 'name');
}

/**
 * @return list<string>
 */
function columnsBesideTitle(Testable $component, CrmEntity $entity): array
{
    return array_values(array_diff(columnOrder($component), [$entity->titleColumn()]));
}

function toggleableColumn(Testable $component, CrmEntity $entity): string
{
    return collect(columnsBesideTitle($component, $entity))
        ->first(fn (string $name): bool => (bool) $component->instance()->getTable()->getColumn($name)?->isToggleable());
}

/**
 * @return list<array<string, mixed>>
 */
function withTitleLast(Testable $component, CrmEntity $entity): array
{
    [$title, $rest] = collect($component->get('tableColumns'))->partition(fn (array $item): bool => $item['name'] === $entity->titleColumn());

    return [...$rest->values()->all(), ...$title->values()->all()];
}

it('moves a column before another and keeps the order on a new visit', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $movable = columnsBesideTitle($component, $entity);
    [$first, $last] = [$movable[0], end($movable)];

    $component->call('moveTableColumn', $last, $first);

    $order = columnOrder($component);
    expect($order[0])->toBe($entity->titleColumn())
        ->and(array_search($last, $order, true))->toBe(array_search($first, $order, true) - 1);

    session()->flush();

    expect(columnOrder(livewire($page)))->toBe($order);
})->with('column menu pages');

it('moves a column to the end when nothing follows it', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $first = columnsBesideTitle($component, $entity)[0];

    $component->call('moveTableColumn', $first, null);

    expect(columnOrder($component))->toHaveCount(count(columnsBesideTitle($component, $entity)) + 1)
        ->and(last(columnOrder($component)))->toBe($first);
})->with('column menu pages');

it('hides a toggleable column and keeps it hidden on a new visit', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $column = toggleableColumn($component, $entity);

    $component->call('hideTableColumn', $column);

    expect(collect($component->get('tableColumns'))->firstWhere('name', $column)['isToggled'])->toBeFalse();

    session()->flush();

    expect(collect(livewire($page)->get('tableColumns'))->firstWhere('name', $column)['isToggled'])->toBeFalse();
})->with('column menu pages');

it('moves the title column and lands another column before it', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $title = $entity->titleColumn();
    $last = last(columnOrder($component));

    $component->call('moveTableColumn', $title, null)->call('moveTableColumn', $last, $title);

    $order = columnOrder($component);
    expect(array_slice($order, -2))->toBe([$last, $title]);

    session()->flush();

    expect(columnOrder(livewire($page)))->toBe($order);
})->with('column menu pages');

it('never hides the title column', function (string $page, CrmEntity $entity): void {
    $component = livewire($page)->call('hideTableColumn', $entity->titleColumn());

    expect(collect($component->get('tableColumns'))->firstWhere('name', $entity->titleColumn())['isToggled'])->toBeTrue()
        ->and(columnMenuPreferences())->toBe(0);
})->with('column menu pages');

it('ignores a column the table does not have', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $order = columnOrder($component);

    $component->call('moveTableColumn', 'custom_fields.gone', columnsBesideTitle($component, $entity)[0])
        ->call('moveTableColumn', columnsBesideTitle($component, $entity)[0], 'custom_fields.gone')
        ->call('hideTableColumn', 'custom_fields.gone');

    expect(columnOrder($component))->toBe($order)
        ->and(columnMenuPreferences())->toBe(0);
})->with('column menu pages');

it('opens with the title column where the stored order put it', function (string $page, CrmEntity $entity): void {
    $columns = collect(withTitleLast(livewire($page), $entity))
        ->map(fn (array $item): array => ['type' => $item['type'], 'name' => $item['name'], 'isToggled' => $item['isToggled']])
        ->all();

    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => $entity,
        'state' => new TableStateData(columns: $columns),
    ]);

    expect(last(columnOrder(livewire($page))))->toBe($entity->titleColumn());
})->with('column menu pages');

it('keeps the title column where the column manager puts it', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);

    $component->call('applyTableColumnManager', withTitleLast($component, $entity), true);

    expect(last(columnOrder($component)))->toBe($entity->titleColumn());
})->with('column menu pages');

it('marks the applied layout modified after a move', function (): void {
    $layout = TableLayout::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(sort: 'name:desc'),
    ]);
    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey());
    $movable = columnsBesideTitle($component, CrmEntity::Company);

    expect($component->instance()->isTableLayoutModified())->toBeFalse();

    $component->call('moveTableColumn', end($movable), $movable[0]);

    expect($component->instance()->isTableLayoutModified())->toBeTrue();
});

it('tells the browser which headers hide', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $table = $component->instance()->getTable();
    $title = $table->getColumn($entity->titleColumn())->getExtraHeaderAttributes();
    $toggleable = $table->getColumn(toggleableColumn($component, $entity))->getExtraHeaderAttributes();

    expect($title)->toMatchArray(['data-column-hideable' => '0'])
        ->and($toggleable)->toMatchArray(['data-column-hideable' => '1']);
})->with('column menu pages');

it('applies a layout that stored the title column elsewhere as stored and unmodified', function (string $page, CrmEntity $entity): void {
    $columns = collect(withTitleLast(livewire($page), $entity))
        ->map(fn (array $item): array => ['type' => $item['type'], 'name' => $item['name'], 'isToggled' => $item['isToggled']])
        ->all();
    $layout = TableLayout::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => $entity,
        'state' => new TableStateData(columns: $columns),
    ]);

    $component = livewire($page)->call('applySavedLayout', $layout->getKey());

    expect(last(columnOrder($component)))->toBe($entity->titleColumn())
        ->and($component->instance()->isTableLayoutModified())->toBeFalse();
})->with('column menu pages');

/**
 * @return list<string>
 */
function pinnedColumns(Testable $component): array
{
    return $component->get('tablePinnedColumns');
}

/**
 * @return list<string>
 */
function shownColumns(Testable $component, CrmEntity $entity): array
{
    return collect($component->get('tableColumns'))
        ->filter(fn (array $item): bool => $item['isToggled'] && $item['name'] !== $entity->titleColumn())
        ->pluck('name')
        ->values()
        ->all();
}

/**
 * @return list<array{type: string, name: string, isToggled: bool}>
 */
function storedColumns(Testable $component): array
{
    return collect($component->get('tableColumns'))
        ->map(fn (array $item): array => ['type' => $item['type'], 'name' => $item['name'], 'isToggled' => $item['isToggled']])
        ->all();
}

it('pins a column and keeps it on a new visit', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $column = shownColumns($component, $entity)[2];

    $component->call('pinTableColumn', $column);

    expect(pinnedColumns($component))->toBe([$column])
        ->and(columnOrder($component)[0])->toBe($column);

    $order = columnOrder($component);
    session()->flush();
    $fresh = livewire($page);

    expect(pinnedColumns($fresh))->toBe([$column])
        ->and(columnOrder($fresh))->toBe($order);
})->with('column menu pages');

it('pins columns in the order they were pinned and unpins to the first unpinned position', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    [, $a, $b] = shownColumns($component, $entity);

    $component->call('pinTableColumn', $b)->call('pinTableColumn', $a);

    expect(array_slice(columnOrder($component), 0, 2))->toBe([$b, $a]);

    $component->call('unpinTableColumn', $b);

    expect(pinnedColumns($component))->toBe([$a])
        ->and(columnOrder($component)[1])->toBe($b);
})->with('column menu pages');

it('pins and unpins the title column like any other column', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $title = $entity->titleColumn();
    $column = shownColumns($component, $entity)[0];

    $component->call('pinTableColumn', $title);

    expect(pinnedColumns($component))->toBe([$title]);

    $component->call('pinTableColumn', $column)->call('unpinTableColumn', $title);

    expect(pinnedColumns($component))->toBe([$column])
        ->and(array_slice(columnOrder($component), 0, 2))->toBe([$column, $title]);
})->with('column menu pages');

it('unpins a column when it is hidden', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $column = toggleableColumn($component, $entity);

    $component->call('pinTableColumn', $column)->call('hideTableColumn', $column);

    expect(pinnedColumns($component))->toBe([]);

    TablePreference::query()->withoutGlobalScopes()->delete();
    $component = livewire($page)->call('pinTableColumn', $column);

    $component->call('applyTableColumnManager', array_map(
        fn (array $item): array => $item['name'] === $column ? [...$item, 'isToggled' => false] : $item,
        $component->get('tableColumns'),
    ), true);

    expect(pinnedColumns($component))->toBe([]);
})->with('column menu pages');

it('keeps a column on its side of the pins when the column manager moves it', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    [$pinned, $unpinned] = shownColumns($component, $entity);

    $component->call('pinTableColumn', $pinned);

    $columns = collect($component->get('tableColumns'));
    [$moved, $rest] = $columns->partition(fn (array $item): bool => $item['name'] === $unpinned);

    $component->call('applyTableColumnManager', [...$moved->values()->all(), ...$rest->values()->all()], true);

    expect(array_slice(columnOrder($component), 0, 2))->toBe([$pinned, $unpinned]);
})->with('column menu pages');

it('drops a pin for a column the table no longer has', function (string $page, CrmEntity $entity): void {
    $title = $entity->titleColumn();
    $component = livewire($page);
    $column = shownColumns($component, $entity)[0];

    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => $entity,
        'state' => new TableStateData(columns: storedColumns($component), pinned: [$title, 'custom_fields.gone', $column]),
    ]);

    $component = livewire($page);

    expect(pinnedColumns($component))->toBe([$title, $column]);

    TablePreference::query()->withoutGlobalScopes()->delete();
    $component = livewire($page)->call('pinTableColumn', 'custom_fields.gone');

    expect(pinnedColumns($component))->toBe([])
        ->and(columnMenuPreferences())->toBe(0);
})->with('column menu pages');

it('restores the pins a layout was saved with and marks the layout modified when they change', function (): void {
    $layout = TableLayout::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(pinned: ['name', 'creator.name']),
    ]);

    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey());

    expect(pinnedColumns($component))->toBe(['name', 'creator.name'])
        ->and(array_slice(columnOrder($component), 0, 2))->toBe(['name', 'creator.name'])
        ->and($component->instance()->isTableLayoutModified())->toBeFalse();

    $component->call('unpinTableColumn', 'creator.name');

    expect($component->instance()->isTableLayoutModified())->toBeTrue();
});

it('marks pinned headers and cells in the render that pinned them', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    [$pinned, $unpinned] = shownColumns($component, $entity);

    $component->call('pinTableColumn', $pinned);

    $table = $component->instance()->getTable();

    expect($table->getColumn($pinned)->getExtraHeaderAttributes())->toMatchArray(['data-column-pinned' => '1'])
        ->and($table->getColumn($pinned)->getExtraCellAttributes())->toMatchArray(['data-column-pinned' => '1'])
        ->and($table->getColumn($unpinned)->getExtraHeaderAttributes())->toMatchArray(['data-column-pinned' => '0'])
        ->and($table->getColumn($entity->titleColumn())->getExtraHeaderAttributes())->toMatchArray(['data-column-pinned' => '0']);
})->with('column menu pages');

it('hashes a state without pins as it did before pins existed', function (): void {
    expect((new TableStateData(sort: 'name:desc'))->hash())->toBe('3e415bb40e6c0fb63a7d1b11578db3ea');
});

function columnLabelText(Testable $component, string $column): string
{
    return trim(strip_tags((string) $component->instance()->getTable()->getColumn($column)->getLabel()));
}

function columnManagerLabel(Testable $component, string $column): string
{
    return trim(strip_tags((string) collect($component->get('tableColumns'))->firstWhere('name', $column)['label']));
}

it('renames a column for the member in the header and the column manager, and keeps it on a new visit', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $column = columnsBesideTitle($component, $entity)[0];

    $component->callAction('editTableColumnLabel', data: ['label' => '  Owner of record  '], arguments: ['column' => $column])
        ->assertHasNoActionErrors();

    expect(columnLabelText($component, $column))->toBe('Owner of record')
        ->and(columnManagerLabel($component, $column))->toBe('Owner of record');

    session()->flush();
    $revisit = livewire($page);

    expect(columnLabelText($revisit, $column))->toBe('Owner of record')
        ->and(columnManagerLabel($revisit, $column))->toBe('Owner of record');
})->with('column menu pages');

it('puts the original name back when the label is cleared', function (): void {
    $component = livewire(ListCompanies::class);
    $original = columnLabelText($component, 'created_at');

    $component->callAction('editTableColumnLabel', data: ['label' => 'Added'], arguments: ['column' => 'created_at'])
        ->callAction('editTableColumnLabel', data: ['label' => ''], arguments: ['column' => 'created_at'])
        ->assertHasNoActionErrors();

    expect(columnLabelText($component, 'created_at'))->toBe($original)
        ->and(TablePreference::query()->withoutGlobalScopes()->sole()->state->labels)->toBe([]);
});

it('refuses a label longer than 60 characters', function (): void {
    livewire(ListCompanies::class)
        ->callAction('editTableColumnLabel', data: ['label' => str_repeat('a', 61)], arguments: ['column' => 'created_at'])
        ->assertHasActionErrors(['label' => 'max']);

    expect(columnMenuPreferences())->toBe(0);
});

it('renames the title column for the member and keeps it on a new visit', function (string $page, CrmEntity $entity): void {
    livewire($page)
        ->callAction('editTableColumnLabel', data: ['label' => 'Record name'], arguments: ['column' => $entity->titleColumn()])
        ->assertHasNoActionErrors();

    session()->flush();
    $revisit = livewire($page);

    expect(columnLabelText($revisit, $entity->titleColumn()))->toBe('Record name')
        ->and(columnManagerLabel($revisit, $entity->titleColumn()))->toBe('Record name');
})->with('column menu pages');

it('never renames a column the table does not have', function (): void {
    livewire(ListCompanies::class)
        ->callAction('editTableColumnLabel', data: ['label' => 'Renamed'], arguments: ['column' => 'custom_fields.gone']);

    expect(columnMenuPreferences())->toBe(0);
});

it('carries labels in a saved layout and marks the layout modified when one changes', function (): void {
    $layout = TableLayout::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(labels: ['created_at' => 'Added']),
    ]);

    $component = livewire(ListCompanies::class)->call('applySavedLayout', $layout->getKey());

    expect(columnLabelText($component, 'created_at'))->toBe('Added')
        ->and($component->instance()->isTableLayoutModified())->toBeFalse();

    $component->callAction('editTableColumnLabel', data: ['label' => 'Joined'], arguments: ['column' => 'created_at']);

    expect($component->instance()->isTableLayoutModified())->toBeTrue();

    $component->call('revertTableLayout');

    expect(columnLabelText($component, 'created_at'))->toBe('Added');
});

it('ignores a saved label for a column the table no longer has', function (): void {
    $columns = collect(livewire(ListCompanies::class)->get('tableColumns'))
        ->map(fn (array $item): array => ['type' => $item['type'], 'name' => $item['name'], 'isToggled' => $item['isToggled']])
        ->all();
    TablePreference::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(columns: $columns, labels: ['custom_fields.gone' => 'Gone', 'created_at' => 'Added']),
    ]);

    $component = livewire(ListCompanies::class);

    expect(columnLabelText($component, 'created_at'))->toBe('Added')
        ->and(array_keys($component->get('tableColumnLabels')))->toBe(['created_at']);
});

it('opens the label editor with the name the column shows and stores nothing for the original name', function (): void {
    $component = livewire(ListCompanies::class);
    $original = columnLabelText($component, 'created_at');

    $component->mountAction('editTableColumnLabel', ['column' => 'created_at'])
        ->assertActionDataSet(['label' => $original])
        ->callMountedAction()
        ->callAction('editTableColumnLabel', data: ['label' => 'Added'], arguments: ['column' => 'created_at'])
        ->mountAction('editTableColumnLabel', ['column' => 'created_at'])
        ->assertActionDataSet(['label' => 'Added'])
        ->callMountedAction()
        ->callAction('editTableColumnLabel', data: ['label' => $original], arguments: ['column' => 'created_at']);

    expect(TablePreference::query()->withoutGlobalScopes()->sole()->state->labels)->toBe([]);
});
