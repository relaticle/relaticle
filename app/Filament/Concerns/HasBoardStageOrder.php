<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Views\PlaceBoardCard;
use App\Actions\Views\ReorderBoardStages;
use App\Actions\Views\SaveBoardState;
use App\Actions\Views\SetBoardColumnField;
use App\Actions\Views\SetBoardColumnVisibility;
use App\Data\BoardStateData;
use App\Enums\BoardColumnWidth;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldValue;
use App\Models\SavedView;
use App\Queries\Views\BoardColumnFieldsQuery;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\CreateAction;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use LogicException;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
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
        $visible = filter_var($visible, FILTER_VALIDATE_BOOL);
        $visibleIds = $this->visibleBoardColumnIds();

        if (! is_string($optionId) || ! $this->canSetColumnVisible($optionId, $visible, $visibleIds)) {
            return;
        }

        $fieldCode = $this->boardColumnField()->code;
        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SetBoardColumnVisibility::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $fieldCode, $optionId, $visible, $view));

        if (! $saved instanceof BoardStateData) {
            return;
        }

        $this->boardStateWritten($saved);
    }

    /**
     * @param  list<string>  $visibleIds
     */
    private function canSetColumnVisible(string $optionId, bool $visible, array $visibleIds): bool
    {
        if ($optionId === BoardStateData::NO_VALUE_COLUMN) {
            return true;
        }

        return $this->boardColumnOptions()->contains('id', $optionId) && ($visible || $visibleIds !== [$optionId]);
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

    public function setBoardColumnWidth(mixed $width = null): void
    {
        $width = is_string($width) ? BoardColumnWidth::tryFrom($width) : null;

        if (! $width instanceof BoardColumnWidth) {
            return;
        }

        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SaveBoardState::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), ['column_width' => $width->value], $view));

        if ($saved instanceof BoardStateData) {
            $this->boardStateWritten($saved);
        }
    }

    public function boardLayoutToggles(): Htmlable
    {
        $state = $this->storedBoardState();

        return new HtmlString(view('filament.app.board-layout-toggles', [
            'columnWidth' => $state->columnWidth,
            'cardSize' => $state->cardSize,
        ])->render());
    }

    public function boardViewSettings(): Htmlable
    {
        $field = $this->boardColumnField();

        $visible = $this->visibleBoardColumnIds();

        return new HtmlString(view('filament.app.board-view-settings', [
            'columns' => $this->boardColumnOptions()
                ->map(static fn (array $option): array => [...$option, 'visible' => in_array($option['id'], $visible, true)])
                ->all(),
            'visibleCount' => count($visible) + ($this->showsNoValueColumn() ? 1 : 0),
            'visibleRealCount' => count($visible),
            'noValueColumn' => ['label' => $this->noValueColumnLabel(), 'on' => $this->showsNoValueColumn()],
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
            ...$this->cardSettingsView(),
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
    protected function hiddenBoardColumnIds(): array
    {
        $ids = array_values($this->boardColumnOptions()->pluck('id')->all());
        $hidden = array_values(array_intersect($ids, $this->storedBoardState()->hiddenColumns));

        return count($hidden) === count($ids) ? [] : $hidden;
    }

    /**
     * @return list<string>
     */
    protected function visibleBoardColumnIds(): array
    {
        $hidden = $this->hiddenBoardColumnIds();

        return array_values($this->boardColumnOptions()
            ->pluck('id')
            ->reject(static fn (string $id): bool => in_array($id, $hidden, true))
            ->all());
    }

    protected function showsNoValueColumn(): bool
    {
        return in_array($this->boardColumnField()->code, $this->storedBoardState()->noValueColumns, true);
    }

    protected function boardColumnIdentifier(): string
    {
        return $this->showsNoValueColumn() ? 'board_column' : $this->boardColumnField()->getValueColumn();
    }

    protected function boardColumnExpression(): string
    {
        $identifier = $this->boardColumnIdentifier();

        return $this->showsNoValueColumn() ? $identifier : "cfv.{$identifier}";
    }

    protected function columnValueFor(string $columnId): ?string
    {
        return $columnId === BoardStateData::NO_VALUE_COLUMN ? null : $columnId;
    }

    protected function ownsColumn(string $id): bool
    {
        return in_array($id, $this->visibleBoardColumnIds(), true)
            || ($id === BoardStateData::NO_VALUE_COLUMN && $this->showsNoValueColumn());
    }

    private function noValueColumnLabel(): string
    {
        return __('filament/pages/boards.view_settings.no_value_column', ['attribute' => $this->boardColumnField()->name]);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function boardColumnQuery(Builder $query, CustomField $field): Builder
    {
        $table = $query->getModel()->getTable();
        $valueColumn = $field->getValueColumn();

        if (! $this->showsNoValueColumn()) {
            return $query
                ->leftJoin('custom_field_values as cfv', function (JoinClause $join) use ($table, $field): void {
                    $join->on("{$table}.id", '=', 'cfv.entity_id')->where('cfv.custom_field_id', '=', $field->getKey());
                })
                ->select("{$table}.*", "cfv.{$valueColumn}");
        }

        $values = CustomFieldValue::query()->withoutGlobalScopes()->toBase()
            ->select('entity_id', "{$valueColumn} as board_value")
            ->where('custom_field_id', $field->getKey());

        $eagerLoads = $query->getEagerLoads();
        $query->setEagerLoads([])
            ->leftJoinSub($values, 'cfv', "{$table}.id", '=', 'cfv.entity_id')
            ->select("{$table}.*")
            ->getQuery()
            ->selectRaw('coalesce(cfv.board_value::text, ?) as board_column', [BoardStateData::NO_VALUE_COLUMN]);

        return $query->getModel()->newQuery()->fromSub($query, $table)->select("{$table}.*")->setEagerLoads($eagerLoads);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function withoutHiddenColumnCards(Builder $query): Builder
    {
        $hidden = $this->hiddenBoardColumnIds();
        $column = $this->boardColumnExpression();

        return $hidden === []
            ? $query
            : $query->where(static fn (Builder $inner): Builder => $inner->whereNull($column)->orWhereNotIn($column, $hidden));
    }

    /**
     * @return list<Column>
     */
    protected function boardColumns(): array
    {
        $hidden = $this->hiddenBoardColumnIds();

        $columns = $this->boardColumnOptions()
            ->map(static fn (array $option): Column => Column::make($option['id'])
                ->color($option['color'])
                ->label($option['name'])
                ->hidden(in_array($option['id'], $hidden, true)))
            ->all();

        return array_values($this->showsNoValueColumn()
            ? [Column::make(BoardStateData::NO_VALUE_COLUMN)->label($this->noValueColumnLabel()), ...$columns]
            : $columns);
    }

    /**
     * @param  list<Action|ActionGroup>  $actions
     * @return list<Action|ActionGroup>
     */
    protected function prefillingFirstVisibleColumn(array $actions): array
    {
        return array_map(
            fn (Action|ActionGroup $action): Action|ActionGroup => $action instanceof CreateAction
                ? $action
                    ->mountUsing(fn (?Schema $schema) => $this->fillWithFirstVisibleColumn($schema))
                    ->after(fn (Model $record) => $this->placeAtBottomOfItsColumn($record))
                : $action,
            $actions,
        );
    }

    protected function placeNewCard(Model $card, string $columnId, string $edge = 'top'): void
    {
        resolve(PlaceBoardCard::class)->execute($this->viewSwitcherUser(), $card, (float) $this->getBoardPositionInColumn($columnId, $edge));
    }

    private function fillWithFirstVisibleColumn(?Schema $schema): void
    {
        if (! $schema instanceof Schema) {
            return;
        }

        $schema->fill();

        $state = $schema->getRawState();
        data_set($state, 'custom_fields.'.$this->boardColumnField()->code, $this->visibleBoardColumnIds()[0] ?? null);
        $schema->rawState($state);
    }

    private function placeAtBottomOfItsColumn(Model $record): void
    {
        if (! $record instanceof HasCustomFields) {
            return;
        }

        $field = $this->boardColumnField();
        $value = $record->customFieldValues()->where('custom_field_id', $field->getKey())->value($field->getValueColumn());

        $columnId = match (true) {
            is_string($value) && $this->boardColumnOptions()->contains('id', $value) => $value,
            blank($value) && $this->showsNoValueColumn() => BoardStateData::NO_VALUE_COLUMN,
            default => null,
        };

        if ($columnId !== null) {
            $this->placeNewCard($record, $columnId, 'bottom');
        }
    }

    abstract protected function defaultBoardColumnField(): ?CustomField;

    /**
     * @return array<string, mixed>
     */
    abstract protected function cardSettingsView(): array;

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
