<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Filament\Tables\Concerns\HasColumnMenu;
use App\Filament\Tables\Concerns\HasFilterBar;
use App\Filament\Tables\Concerns\HasResizableColumns;
use App\Filament\Tables\Concerns\HasSavedViews;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Models\CustomField;
use BackedEnum;
use Filament\Tables\Columns\Column;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\CustomFields\Facades\CustomFields;

trait HasCustomFieldColumns
{
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

                return $field instanceof CustomField
                    ? $column->label(fn (): Htmlable => $this->columnHeaderLabel($column->getName(), $field->name, $field->typeData->icon))
                    : $column;
            })
            ->all();

        $titleColumn = $this->getTableTitleColumn();

        foreach ($table->getColumns() as $column) {
            $label = $column->getLabel();

            if ($label instanceof Htmlable) {
                continue;
            }

            $icon = $column->getName() === $titleColumn ? null : self::nativeColumnIcon($column->getName());

            $column->label(fn (): Htmlable => $this->columnHeaderLabel($column->getName(), $label, $icon));
        }

        return $this->stampResizableColumns(
            $table
                ->modifyQueryUsing(function (Builder $query): void {
                    $query->with('customFieldValues.customField');
                })
                ->deferFilters(false)
                ->filtersLayout(FiltersLayout::Hidden)
                ->columnManager(false)
                ->pushColumns($columns)
                ->pushFilters($builder->filters()->all()),
        );
    }

    private function columnHeaderLabel(string $columnName, string $original, string|BackedEnum|null $icon): Htmlable
    {
        $label = $this->tableColumnLabel($columnName, $original);

        // Stacked rows print the label in every cell, so each label is built once per request.
        return $this->columnHeaderLabels["{$columnName}\0{$label}"] ??= ColumnHeaderLabel::make($label, $icon);
    }

    private static function nativeColumnIcon(string $name): string
    {
        return match ($name) {
            'accountOwner.name' => 'lucide-user',
            'creator.name' => 'lucide-circle-user',
            'assignees.name' => 'lucide-users',
            'company.name' => 'lucide-building-complex',
            'relations' => 'lucide-link',
            'created_at' => 'lucide-calendar',
            'updated_at' => 'lucide-clock',
            'deleted_at' => 'lucide-trash',
            default => 'lucide-text-align-start',
        };
    }
}
