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
use App\Models\SavedView;
use App\Models\TablePreference;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Features\SupportTesting\Testable;
use Symfony\Component\DomCrawler\Crawler;

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

it('puts the columns back in their declared order with the standard view', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $declared = columnOrder($component);
    $movable = columnsBesideTitle($component, $entity);

    $component->call('moveTableColumn', end($movable), $movable[0])->call('useStandardTable');

    expect(columnOrder($component))->toBe($declared);

    session()->flush();

    expect(columnOrder(livewire($page)))->toBe($declared);
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

it('marks the applied view modified after a move', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(sort: 'name:desc'),
    ]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());
    $movable = columnsBesideTitle($component, CrmEntity::Company);

    expect($component->instance()->isSavedViewModified())->toBeFalse();

    $component->call('moveTableColumn', end($movable), $movable[0]);

    expect($component->instance()->isSavedViewModified())->toBeTrue();
});

it('tells the browser which headers hide', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $table = $component->instance()->getTable();
    $title = $table->getColumn($entity->titleColumn())->getExtraHeaderAttributes();
    $toggleable = $table->getColumn(toggleableColumn($component, $entity))->getExtraHeaderAttributes();

    expect($title)->toMatchArray(['data-column-hideable' => '0'])
        ->and($toggleable)->toMatchArray(['data-column-hideable' => '1']);
})->with('column menu pages');

it('applies a view that stored the title column elsewhere as stored and unmodified', function (string $page, CrmEntity $entity): void {
    $columns = collect(withTitleLast(livewire($page), $entity))
        ->map(fn (array $item): array => ['type' => $item['type'], 'name' => $item['name'], 'isToggled' => $item['isToggled']])
        ->all();
    $view = SavedView::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => $entity,
        'state' => new TableStateData(columns: $columns),
    ]);

    $component = livewire($page)->call('applySavedView', $view->getKey());

    expect(last(columnOrder($component)))->toBe($entity->titleColumn())
        ->and($component->instance()->isSavedViewModified())->toBeFalse();
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

it('never moves a pinned column', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    [$first, $second] = shownColumns($component, $entity);

    $component->call('pinTableColumn', $first)->call('pinTableColumn', $second);
    $order = columnOrder($component);

    $component->call('moveTableColumn', $first, null)->call('moveTableColumn', $second, $first);

    expect(columnOrder($component))->toBe($order)
        ->and(pinnedColumns($component))->toBe([$first, $second]);
})->with('column menu pages');

it('clears the pins when the column manager resets the columns', function (string $page, CrmEntity $entity): void {
    $component = livewire($page);
    $defaultOrder = columnOrder($component);
    $column = shownColumns($component, $entity)[2];

    $component->call('pinTableColumn', $column)->call('resetTableColumnManager');

    expect(pinnedColumns($component))->toBe([])
        ->and(columnOrder($component))->toBe($defaultOrder)
        ->and($component->instance()->getTable()->getColumn($column)->getExtraHeaderAttributes())->toMatchArray(['data-column-pinned' => '0']);

    session()->flush();

    expect(pinnedColumns(livewire($page)))->toBe([]);
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

it('restores the pins a view was saved with and marks the view modified when they change', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(pinned: ['name', 'creator.name']),
    ]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());

    expect(pinnedColumns($component))->toBe(['name', 'creator.name'])
        ->and(array_slice(columnOrder($component), 0, 2))->toBe(['name', 'creator.name'])
        ->and($component->instance()->isSavedViewModified())->toBeFalse();

    $component->call('unpinTableColumn', 'creator.name');

    expect($component->instance()->isSavedViewModified())->toBeTrue();
});

it('marks the pins of a view in the render that applied it and clears them in the render that left it', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(pinned: ['name', 'creator.name']),
    ]);
    $component = livewire(ListCompanies::class);
    $pinned = fn (string $column): string => $component->instance()->getTable()->getColumn($column)->getExtraHeaderAttributes()['data-column-pinned'];

    $component->call('applySavedView', $view->getKey());

    expect($pinned('name'))->toBe('1')
        ->and($pinned('creator.name'))->toBe('1');

    $component->call('useStandardTable');

    expect($pinned('name'))->toBe('0')
        ->and($pinned('creator.name'))->toBe('0');
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

it('escapes markup in a column label in the header and the column manager', function (): void {
    $component = livewire(ListCompanies::class);

    $component->callAction('editTableColumnLabel', data: ['label' => '<img src=x onerror=alert(1)>'], arguments: ['column' => 'created_at']);

    $managerLabel = (string) collect($component->get('tableColumns'))->firstWhere('name', 'created_at')['label'];

    expect($managerLabel)->toContain('&lt;img src=x onerror=alert(1)&gt;')->not->toContain('<img src=x')
        ->and($component->html())->toContain('&lt;img src=x onerror=alert(1)&gt;')->not->toContain('<img src=x');
});

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

it('carries labels in a saved view and marks the view modified when one changes', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->user->currentWorkspace)->create([
        'entity' => CrmEntity::Company,
        'state' => new TableStateData(labels: ['created_at' => 'Added']),
    ]);

    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey());

    expect(columnLabelText($component, 'created_at'))->toBe('Added')
        ->and($component->instance()->isSavedViewModified())->toBeFalse();

    $component->callAction('editTableColumnLabel', data: ['label' => 'Joined'], arguments: ['column' => 'created_at']);

    expect($component->instance()->isSavedViewModified())->toBeTrue();

    $component->call('revertSavedView');

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

it('names the column menu for assistive technology', function (): void {
    Filament::bootCurrentPanel();

    livewire(ListCompanies::class)->assertSeeHtmlInOrder(['role="menu"', 'aria-label="Column options"']);
});

function columnIsShown(Testable $component, string $column): bool
{
    return collect($component->get('tableColumns'))->firstWhere('name', $column)['isToggled'];
}

it('hides and shows a column from view settings', function (): void {
    $component = livewire(ListCompanies::class)->call('toggleTableColumn', 'created_at');
    $before = columnIsShown($component, 'created_at');

    $component->call('toggleTableColumn', 'created_at');

    expect(columnIsShown($component, 'created_at'))->toBe(! $before);
});

it('never hides the title column from view settings', function (string $page, CrmEntity $entity): void {
    $component = livewire($page)->call('toggleTableColumn', $entity->titleColumn());

    expect(columnIsShown($component, $entity->titleColumn()))->toBeTrue();
})->with('column menu pages');

it('ignores a column the table does not have in view settings', function (): void {
    $component = livewire(ListCompanies::class);
    $before = $component->get('tableColumns');

    $component->call('toggleTableColumn', 'custom_fields.gone');

    expect($component->get('tableColumns'))->toBe($before);
});

it('lists a hidden column under add column and shows it again at the end', function (): void {
    Filament::bootCurrentPanel();
    $component = livewire(ListCompanies::class);
    $first = columnsBesideTitle($component, CrmEntity::Company)[0];

    $component->call('toggleTableColumn', $first);

    expect($component->html())->toContain('Show '.columnLabelText($component, $first));

    $component->call('toggleTableColumn', $first);

    expect(columnIsShown($component, $first))->toBeTrue()
        ->and(last(columnOrder($component)))->toBe($first);
});

it('draws no column manager trigger in the toolbar', function (): void {
    expect(livewire(ListCompanies::class)->instance()->getTable()->hasColumnManager())->toBeFalse();
});

it('offers a reset only on the built-in list', function (): void {
    Filament::bootCurrentPanel();
    $view = SavedView::factory()->for($this->user)->for($this->user->currentWorkspace)->create(['entity' => CrmEntity::Company]);

    livewire(ListCompanies::class)->assertSeeText('Reset to default')
        ->call('applySavedView', $view->getKey())
        ->assertDontSeeText('Reset to default');
});

it('shows a renamed column with its new label in view settings', function (): void {
    Filament::bootCurrentPanel();
    $component = livewire(ListCompanies::class);
    $column = columnsBesideTitle($component, CrmEntity::Company)[0];

    $component->callAction('editTableColumnLabel', data: ['label' => 'Owner of record'], arguments: ['column' => $column]);

    expect($component->html())->toContain('aria-label="Options for Owner of record"');
});

it('draws every view settings row with its icon and a menu button and no eye button', function (string $page, CrmEntity $entity): void {
    Filament::bootCurrentPanel();
    $rows = new Crawler(livewire($page)->html())->filter('[data-view-settings] .fi-view-settings-row');

    expect($rows->count())->toBeGreaterThan(1);

    $rows->each(function (Crawler $row) use ($entity): void {
        expect($row->filter('.fi-view-settings-label .fi-icon')->count())->toBe(1)
            ->and($row->filter('[data-view-settings-menu]')->count())->toBe(1)
            ->and($row->filter('[data-view-settings-toggle]')->count())->toBe(0)
            ->and($row->attr('data-column') !== $entity->titleColumn() || $row->attr('data-column-locked') !== null)->toBeTrue();
    });
})->with('column menu pages');

it('words the row menu and the header menu from the same keys', function (): void {
    Filament::bootCurrentPanel();
    $html = livewire(ListCompanies::class)->html();

    expect(substr_count($html, 'Change attribute label'))->toBe(2)
        ->and(substr_count($html, 'Remove column'))->toBe(2)
        ->and($html)->not->toContain('Edit column label')->not->toContain('Hide column');
});

it('escapes markup in a column label in view settings', function (): void {
    Filament::bootCurrentPanel();
    $component = livewire(ListCompanies::class);

    $component->callAction('editTableColumnLabel', data: ['label' => '<img src=x onerror=alert(1)>'], arguments: ['column' => 'created_at']);

    $html = $component->html();
    $panel = new Crawler($html)->filter('[data-view-settings]');

    expect($panel)->toHaveCount(1)
        ->and($panel->html())->toContain('&lt;img src=x onerror=alert(1)&gt;')->not->toContain('<img src=x')
        ->and($html)->not->toContain('<img src=x');
});

it('lists the pinned columns first in view settings and marks them', function (): void {
    Filament::bootCurrentPanel();
    $component = livewire(ListCompanies::class)->call('pinTableColumn', 'created_at');

    $component->assertSeeHtmlInOrder(['data-column="created_at"', 'data-column-pinned', 'data-column="name"']);
});

it('keeps the member\'s widths when the table goes back to its default', function (): void {
    $component = livewire(ListCompanies::class)
        ->call('sortTable', 'name', 'asc')
        ->call('updateResizableColumnWidth', 'created_at', '300')
        ->call('pinTableColumn', 'created_at')
        ->call('useStandardTable');

    expect($component->get('tableColumnWidths'))->toBe(['created_at' => 300])
        ->and($component->get('tableSort'))->toBeNull()
        ->and(pinnedColumns($component))->toBe([]);
});

it('offers the reset again once the applied view is deleted elsewhere', function (): void {
    Filament::bootCurrentPanel();
    $view = SavedView::factory()->for($this->user)->for($this->user->currentWorkspace)->create(['entity' => CrmEntity::Company]);
    $component = livewire(ListCompanies::class)->call('applySavedView', $view->getKey())->assertDontSeeText('Reset to default');

    $view->delete();

    $component->call('$refresh')->assertSeeText('Reset to default');
});

it('resets the table to its default from the confirmation and keeps the widths', function (): void {
    $component = livewire(ListCompanies::class)
        ->call('sortTable', 'name', 'asc')
        ->call('updateResizableColumnWidth', 'created_at', '300')
        ->call('toggleTableColumn', 'accountOwner.name')
        ->callAction('resetTableToDefault')
        ->assertHasNoActionErrors();

    expect($component->get('tableSort'))->toBeNull()
        ->and(columnIsShown($component, 'accountOwner.name'))->toBeTrue()
        ->and($component->get('tableColumnWidths'))->toBe(['created_at' => 300]);
});

it('hides the reset action while a saved view is applied', function (): void {
    $view = SavedView::factory()->for($this->user)->for($this->user->currentWorkspace)->create(['entity' => CrmEntity::Company]);

    livewire(ListCompanies::class)->assertActionVisible('resetTableToDefault')
        ->call('applySavedView', $view->getKey())
        ->assertActionHidden('resetTableToDefault');
});
