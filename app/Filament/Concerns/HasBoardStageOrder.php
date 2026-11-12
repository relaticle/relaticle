<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Views\ReorderBoardStages;
use App\Actions\Views\SetBoardColumnField;
use App\Actions\Views\SetBoardColumnVisibility;
use App\Data\BoardStateData;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\SavedView;
use App\Queries\Views\BoardColumnFieldsQuery;
use Closure;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use LogicException;
use Relaticle\Flowforge\Column;

trait HasBoardStageOrder
{
    /** @var Collection<string, CustomField>|null */
    private ?Collection $boardColumnFieldList = null;

    /**
     * @param  list<mixed>  $optionIds
     */
    public function reorderBoardStages(array $optionIds): void
    {
        $columnIds = $this->boardColumnOptions()->pluck('id')->all();
        $known = array_values(array_intersect(array_filter($optionIds, is_string(...)), $columnIds));

        if ($known === []) {
            return;
        }

        $fieldCode = $this->boardColumnField()->code;
        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(ReorderBoardStages::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $known, $view, $fieldCode));

        if (! $saved instanceof BoardStateData) {
            return;
        }

        $this->boardStateWritten($saved);
    }

    public function setBoardColumnVisible(mixed $optionId = null, mixed $visible = true): void
    {
        if (! is_string($optionId)) {
            return;
        }

        $fieldCode = $this->boardColumnField()->code;
        $visible = filter_var($visible, FILTER_VALIDATE_BOOL);
        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SetBoardColumnVisibility::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $fieldCode, $optionId, $visible, $view));

        if (! $saved instanceof BoardStateData) {
            return;
        }

        $this->boardStateWritten($saved);
    }

    public function setBoardColumnField(mixed $code = null): void
    {
        if ($code !== null && (! is_string($code) || ! $this->boardColumnFields()->has($code))) {
            return;
        }

        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SetBoardColumnField::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $code, $view));

        if (! $saved instanceof BoardStateData) {
            return;
        }

        $this->boardStateWritten($saved);
    }

    public function boardViewSettings(): Htmlable
    {
        $field = $this->boardColumnField();

        $visible = $this->visibleBoardColumnIds();

        return new HtmlString(view('filament.app.board-view-settings', [
            'columns' => $this->boardColumnOptions()
                ->map(static fn (array $option): array => [...$option, 'visible' => in_array($option['id'], $visible, true)])
                ->all(),
            'visibleCount' => count($visible),
            'groupings' => $this->boardGroupings(),
            'grouping' => $this->getBoard()->getActiveGrouping()?->getName(),
            'columnFields' => $this->boardColumnFields()
                ->map(static fn (CustomField $candidate): array => [
                    'code' => $candidate->code,
                    'name' => $candidate->name,
                    'icon' => CustomFieldType::from($candidate->type)->iconPath(),
                ])
                ->values()
                ->all(),
            'columnField' => $field->code,
            'columnFieldName' => $field->name,
        ])->render());
    }

    /**
     * @return Collection<string, CustomField>
     */
    protected function boardColumnFields(): Collection
    {
        return $this->boardColumnFieldList ??= resolve(BoardColumnFieldsQuery::class)->get(
            $this->viewSwitcherUser(),
            $this->viewSwitcherWorkspace(),
            $this->viewSwitcherEntity(),
        );
    }

    protected function boardColumnField(): CustomField
    {
        return $this->resolvedBoardColumnField ??= $this->resolveBoardColumnField();
    }

    private function resolveBoardColumnField(): CustomField
    {
        $stored = $this->storedBoardState()->columnField;

        return ($stored === null ? null : $this->boardColumnFields()->get($stored))
            ?? $this->defaultBoardColumnField()
            ?? throw new LogicException('The board has no field to make columns from.');
    }

    /**
     * @return Collection<int, array{id: string, name: string, color: string}>
     */
    protected function boardColumnOptions(): Collection
    {
        $field = $this->boardColumnField();
        $colorsEnabled = $field->settings->enable_option_colors ?? false;

        $options = $field->options->map(static fn (CustomFieldOption $option): array => [
            'id' => (string) $option->getKey(),
            'name' => (string) $option->getAttribute('name'),
            'color' => $colorsEnabled ? ($option->settings->color ?? 'gray') : 'gray',
        ]);

        $order = $this->storedBoardState()->orderStages(array_column($options->all(), 'id'));

        return $options
            ->sortBy(static fn (array $option): int|false => array_search($option['id'], $order, true))
            ->values();
    }

    /**
     * @return list<string>
     */
    protected function visibleBoardColumnIds(): array
    {
        $hidden = $this->storedBoardState()->hiddenColumns;

        return array_values($this->boardColumnOptions()
            ->pluck('id')
            ->reject(static fn (string $id): bool => in_array($id, $hidden, true))
            ->all());
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function withoutHiddenColumnCards(Builder $query): Builder
    {
        $hidden = $this->storedBoardState()->hiddenColumns;
        $hiddenOfField = $this->boardColumnOptions()->pluck('id')->intersect($hidden)->values()->all();

        return $hiddenOfField === []
            ? $query
            : $query->whereNotIn('cfv.'.$this->boardColumnField()->getValueColumn(), $hiddenOfField);
    }

    /**
     * @return list<Column>
     */
    protected function boardColumns(): array
    {
        $hidden = $this->storedBoardState()->hiddenColumns;

        return array_values($this->boardColumnOptions()
            ->map(static fn (array $option): Column => Column::make($option['id'])
                ->color($option['color'])
                ->label($option['name'])
                ->hidden(in_array($option['id'], $hidden, true)))
            ->all());
    }

    abstract protected function defaultBoardColumnField(): ?CustomField;

    /**
     * @return list<array{id: string, label: string, icon: ?string}>
     */
    abstract protected function boardGroupings(): array;

    abstract protected function storedBoardState(): BoardStateData;

    abstract protected function boardStateWritten(BoardStateData $state): void;

    abstract protected function activeBoardView(): ?SavedView;

    /**
     * @param  Closure(?SavedView): BoardStateData  $write
     */
    abstract protected function writingBoardState(Closure $write): ?BoardStateData;
}
