<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Js;
use Livewire\Component;

final class PreviewRecordAction extends ViewAction
{
    /** @var class-string<ViewCompany|ViewPeople|ViewOpportunity> */
    private string $page;

    public static function getDefaultName(): string
    {
        return 'preview';
    }

    public static function mountForRecord(Model $record): string
    {
        return '$wire.mountAction('.Js::from(self::getDefaultName()).', '.Js::from(['record' => (string) $record->getKey()]).')';
    }

    /**
     * @param  class-string<ViewCompany|ViewPeople|ViewOpportunity>  $page
     */
    public function page(string $page): static
    {
        $this->page = $page;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament/record-page.actions.open_preview'))
            ->tableIcon('lucide-panel-right')
            ->groupedIcon('lucide-panel-right')
            ->authorize('view')
            ->slideOver()
            ->modalClickThrough()
            ->modalWidth(Width::FourExtraLarge)
            ->modalCancelAction(false)
            ->modalHeading(fn (Model $record): string => (string) $record->getAttribute('name'))
            ->extraModalWindowAttributes(fn (Component $livewire): array => [
                'class' => 'fi-record-preview',
                'x-on:click.outside' => $this->closeOnOutsideClick($this->modalId($livewire)),
                'x-on:modal-closed.window' => $this->forgetTabOnClose($this->modalId($livewire)),
            ])
            ->modalContent(fn (Model $record, Component $livewire, array $arguments): View => view('filament.app.record-preview-header', [
                'actionName' => $this->getName(),
                'modalId' => $this->modalId($livewire),
                'recordUrl' => Filament::getModelResource($record)::getUrl('view', ['record' => $record]),
                'pluralLabel' => Filament::getModelResource($record)::getTitleCasePluralModelLabel(),
                ...$this->neighbours($record, $livewire, $arguments['position'] ?? null),
            ]))
            ->schema(fn (Schema $schema, ?Model $record): Schema => $record instanceof Model ? $schema->components([
                Livewire::make($this->page, ['record' => $record->getKey(), 'isEmbedded' => true])
                    ->key("record-preview-{$record->getKey()}"),
            ]) : $schema);
    }

    private function modalId(Component $livewire): string
    {
        return "fi-{$livewire->getId()}-action-{$this->getNestingIndex()}";
    }

    private function forgetTabOnClose(string $modalId): string
    {
        return sprintf(
            "if (\$event.detail.id === %s) { const url = new URL(window.location.href); url.searchParams.delete('relation'); history.replaceState(history.state, '', url) }",
            Js::from($modalId),
        );
    }

    private function closeOnOutsideClick(string $modalId): string
    {
        return sprintf(
            "if (! \$event.target.closest('.fi-modal, .fi-record-preview-trigger, .fi-dropdown-panel, [data-tippy-root], .fi-no')) \$dispatch('close-modal', { id: %s })",
            Js::from($modalId),
        );
    }

    /**
     * @return array{position: ?int, total: int, previousKey: ?string, nextKey: ?string}
     */
    private function neighbours(Model $record, Component $livewire, mixed $position): array
    {
        $unplaced = ['position' => null, 'total' => 0, 'previousKey' => null, 'nextKey' => null];

        if (! $livewire instanceof ListRecords) {
            return $unplaced;
        }

        $query = $livewire->getFilteredSortedTableQuery();

        if (! $query instanceof Builder) {
            return $unplaced;
        }

        $window = is_numeric($position) ? $this->windowAround($query, $record, (int) $position) : null;
        $pagePosition = $window === null ? $this->positionOnCurrentPage($record, $livewire) : null;
        $window ??= $pagePosition === null ? null : $this->windowAround($query, $record, $pagePosition);

        if ($window === null) {
            return $unplaced;
        }

        return [...$window, 'total' => $livewire->getAllTableRecordsCount()];
    }

    /**
     * @param  Builder<Model>  $query
     * @return array{position: int, previousKey: ?string, nextKey: ?string}|null
     */
    private function windowAround(Builder $query, Model $record, int $position): ?array
    {
        $start = max($position - 1, 0);
        $keys = (clone $query)->toBase()
            ->offset($start)
            ->limit(3)
            ->pluck($record->getQualifiedKeyName())
            ->map(fn (mixed $key): string => (string) $key)
            ->all();
        $index = $position - $start;

        if (($keys[$index] ?? null) !== (string) $record->getKey()) {
            return null;
        }

        return [
            'position' => $position,
            'previousKey' => $keys[$index - 1] ?? null,
            'nextKey' => $keys[$index + 1] ?? null,
        ];
    }

    private function positionOnCurrentPage(Model $record, ListRecords $livewire): ?int
    {
        $records = $livewire->getTableRecords();
        $rows = array_values($records instanceof Collection ? $records->all() : $records->items());
        $offset = $records instanceof LengthAwarePaginator
            ? ($records->currentPage() - 1) * $records->perPage()
            : 0;

        foreach ($rows as $index => $row) {
            if ($row instanceof Model && $row->is($record)) {
                return $offset + $index;
            }
        }

        return null;
    }
}
