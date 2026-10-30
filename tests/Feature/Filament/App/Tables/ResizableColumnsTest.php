<?php

declare(strict_types=1);

use App\Enums\CrmEntity;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\NoteResource\Pages\ManageNotes;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Tables\Concerns\HasResizableColumns;
use App\Filament\Tables\ResizableColumns;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Tables\Columns\Column;
use Livewire\Features\SupportTesting\Testable;

mutates(HasResizableColumns::class, ResizableColumns::class);

dataset('list pages', [
    'companies' => ListCompanies::class,
    'people' => ListPeople::class,
    'opportunities' => ListOpportunities::class,
    'tasks' => ManageTasks::class,
    'notes' => ManageNotes::class,
]);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

/**
 * @return array<string, mixed>
 */
function headerAttributes(Testable $component, string $column): array
{
    return $component->instance()->getTable()->getColumn($column)->getExtraHeaderAttributes();
}

function titleColumnOf(Testable $component): string
{
    $model = $component->instance()->getTable()->getModel();

    return CrmEntity::tryFromModel(new $model)->titleColumn();
}

/**
 * @return array<int, array<string, mixed>>
 */
function withColumnToggled(Testable $component, string $column, bool $isToggled): array
{
    return collect($component->get('tableColumns'))
        ->map(fn (array $item): array => $item['name'] === $column ? [...$item, 'isToggled' => $isToggled] : $item)
        ->all();
}

it('marks every column resizable', function (string $page): void {
    $component = livewire($page);

    foreach (array_keys($component->instance()->getTable()->getColumns()) as $column) {
        expect(headerAttributes($component, $column)['x-data'])->toStartWith("resizableColumn('{$column}'");
    }
})->with('list pages');

it('gives a column an exact default width', function (string $page): void {
    $attributes = headerAttributes($component = livewire($page), titleColumnOf($component));

    expect($attributes['data-resizable-width'])->toBe('180')
        ->and($attributes['style'])->toBe('min-width: 180px; width: 180px; max-width: 180px');
})->with('list pages');

it('keeps a width across visits', function (string $page): void {
    livewire($page)->call('updateResizableColumnWidth', 'created_at', '300');
    session()->flush();

    $component = livewire($page);

    expect($component->instance()->getResizableColumnWidths())->toBe(['created_at' => 300])
        ->and(headerAttributes($component, 'created_at')['data-resizable-width'])->toBe('300');
})->with('list pages');

it('clamps a width to the allowed range', function (string $sent, int $stored): void {
    livewire(ListCompanies::class)->call('updateResizableColumnWidth', 'created_at', $sent);

    expect(livewire(ListCompanies::class)->instance()->getResizableColumnWidths())
        ->toBe(['created_at' => $stored]);
})->with([
    'below the minimum' => ['5', ResizableColumns::MIN_WIDTH],
    'above the maximum' => ['99999', ResizableColumns::MAX_WIDTH],
]);

it('ignores a width that is not a positive number', function (string $sent): void {
    livewire(ListCompanies::class)->call('updateResizableColumnWidth', 'created_at', $sent);

    expect(livewire(ListCompanies::class)->instance()->getResizableColumnWidths())->toBe([]);
})->with(['0', '-5', 'abc']);

it('ignores a width for a column the table does not have', function (): void {
    livewire(ListCompanies::class)->call('updateResizableColumnWidth', 'not_a_column', '300');

    expect(livewire(ListCompanies::class)->instance()->getResizableColumnWidths())->toBe([]);
});

it('keeps the width of a column hidden and shown again', function (): void {
    $component = livewire(ListCompanies::class)
        ->call('updateResizableColumnWidth', 'created_at', '300');

    $component->call('applyTableColumnManager', withColumnToggled($component, 'created_at', false));
    $component->call('applyTableColumnManager', withColumnToggled($component, 'created_at', true));

    expect(headerAttributes($component, 'created_at')['data-resizable-width'])->toBe('300');
    $component->assertSeeHtml('data-resizable-width="300"');
});

it('keeps widths apart per page', function (): void {
    livewire(ListCompanies::class)->call('updateResizableColumnWidth', 'created_at', '300');

    expect(livewire(ListPeople::class)->instance()->getResizableColumnWidths())->toBe([]);
});

it('renders a saved width over a width the column sets itself', function (): void {
    $component = livewire(ListPeople::class);
    $column = collect($component->instance()->getTable()->getColumns())
        ->first(fn (Column $column): bool => filled($column->getWidth()) && $column->getWidth() !== '180px');

    $component->call('updateResizableColumnWidth', $column->getName(), '500');

    $columnId = headerAttributes($component, $column->getName())['data-resizable-column-id'];
    preg_match('/<th[^>]*data-resizable-column-id="'.preg_quote($columnId, '/').'"[^>]*style="([^"]*)"/s', livewire(ListPeople::class)->html(), $match);

    expect($match[1])->toContain('width: 500px')->not->toContain("width: {$column->getWidth()}");
});

it('uses the default width for a column whose own width is not in pixels', function (string $width): void {
    $component = livewire(ListCompanies::class);
    $table = $component->instance()->getTable();
    $table->getColumn('created_at')->width($width);

    $table->resizableColumns();

    expect(headerAttributes($component, 'created_at')['data-resizable-width'])->toBe('180');
})->with(['10rem', '50%']);

it('keeps a pixel width the column sets itself', function (string $width): void {
    $component = livewire(ListCompanies::class);
    $table = $component->instance()->getTable();
    $table->getColumn('created_at')->width($width);

    $table->resizableColumns();

    expect(headerAttributes($component, 'created_at')['data-resizable-width'])->toBe('240');
})->with(['240px', '240']);
