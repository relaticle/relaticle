<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\CrmEntity;
use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Filament\Tables\Concerns\CreatesTableAttribute;
use App\Filament\Tables\Concerns\HasColumnMenu;
use App\Filament\Tables\Concerns\HasFilterBar;
use App\Filament\Tables\Concerns\HasResizableColumns;
use App\Filament\Tables\Concerns\HasSavedViews;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Models\CustomField;
use BackedEnum;
use Filament\Tables\Columns\Column;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Relaticle\CustomFields\Facades\CustomFields;

trait HasCustomFieldColumns
{
    use CreatesTableAttribute;
    use HasColumnMenu;
    use HasFilterBar;
    use HasResizableColumns;
    use HasSavedViews;
    use PersistsTableState;

    /** @var array<string, Htmlable> */
    private array $columnHeaderLabels = [];

    public function table(Table $table): Table
    {
        $builder = CustomFields::table()->forModel($this->getModel());

        $fields = $builder->getFields()->keyBy(fn (CustomField $field): string => $field->getFieldName());

        $columns = $builder->columns()
            ->map(function (Column $column) use ($fields): Column {
                $field = $fields->get($column->getName());

                if (! $field instanceof CustomField) {
                    return $column;
                }

                $this->originalTableColumnLabels[$column->getName()] = $field->name;

                return $column->label(fn (): Htmlable => $this->columnHeaderLabel($column->getName(), $field->name, $field->typeIcon()));
            })
            ->all();

        $titleColumn = $this->getTableTitleColumn();

        foreach ($table->getColumns() as $column) {
            $label = $column->getLabel();

            if ($label instanceof Htmlable) {
                continue;
            }

            $this->originalTableColumnLabels[$column->getName()] = (string) $label;

            $icon = $column->getName() === $titleColumn ? null : FilterFieldCatalog::columnIcon($column->getName());

            $column->label(fn (): Htmlable => $this->columnHeaderLabel($column->getName(), $label, $icon));
        }

        return $this->stampResizableColumns(
            $table
                ->modifyQueryUsing(function (Builder $query): void {
                    $query->with('customFieldValues.customField');
                })
                ->searchable(false)
                ->deferFilters(false)
                ->filtersLayout(FiltersLayout::Hidden)
                ->columnManager(false)
                ->groupingSettingsHidden()
                ->recordActionsColumnLabel(fn (): ?Htmlable => $this->addColumnHeader())
                ->emptyStateIcon($this->tableEntity()->icon())
                ->pushColumns($columns)
                ->filters([ConditionsFilter::make($this->healedFilterRules(...)), TrashedFilter::make()->indicateUsing(fn (): array => [])]),
        );
    }

    // The actions column takes the width the data columns leave, so its header carries the Add column button.
    private function addColumnHeader(): ?Htmlable
    {
        return $this->addColumnMenu() === null ? null : new HtmlString(view('filament.app.tables.add-column')->render());
    }

    // Rendered beside the table, not in its header, because the table's sideways scroll would clip it.
    public function getAddColumnMenu(): ?Htmlable
    {
        $menu = $this->addColumnMenu();

        return $menu === null ? null : new HtmlString(view('filament.app.tables.add-column-menu', $menu)->render());
    }

    /**
     * @return array{hidden: list<array{name: string, label: Htmlable, text: string, isShown: bool, isPinned: bool, isLocked: bool}>, types: array<string, array{label: string, icon: ?string}>, entityLabel: string}|null
     */
    private function addColumnMenu(): ?array
    {
        $canCreate = $this->canCreateTableAttribute();
        $hidden = array_values(array_filter($this->viewSettingsColumns(), fn (array $column): bool => ! $column['isShown'] && ! $column['isLocked']));

        if ($hidden === [] && ! $canCreate) {
            return null;
        }

        return [
            'hidden' => $hidden,
            'types' => $canCreate ? $this->tableAttributeTypes() : [],
            'entityLabel' => static::getResource()::getModelLabel(),
        ];
    }

    private function columnHeaderLabel(string $columnName, string $original, string|BackedEnum|null $icon): Htmlable
    {
        $label = $this->tableColumnLabel($columnName, $original);

        // Stacked rows print the label in every cell, so each label is built once per request.
        return $this->columnHeaderLabels["{$columnName}\0{$label}"] ??= ColumnHeaderLabel::make($label, $icon);
    }

    protected function attributeEntity(): CrmEntity
    {
        return $this->tableEntity();
    }

    protected function attributeCreated(CustomField $field): void
    {
        $this->showCreatedTableAttribute($field);
    }

    private function showCreatedTableAttribute(CustomField $field): void
    {
        $this->rebuildTableColumns();

        $this->notifyTableAttributeAdded($field);
    }

    // The table is built once per request, before the field exists, so it is built again to carry the new column.
    private function rebuildTableColumns(): void
    {
        $this->table = $this->table($this->makeTable());
        $this->cachedDefaultTableColumnState = null;
        $this->declaredTableColumnNames = null;

        $this->applyTableColumnManager($this->tableColumns, wasReordered: true);
    }
}
