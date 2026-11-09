<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Filament\Tables\Concerns\HasColumnMenu;
use App\Filament\Tables\Concerns\HasResizableColumns;
use App\Filament\Tables\Concerns\HasTableLayouts;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Models\CustomField;
use Filament\Tables\Columns\Column;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\CustomFields\Facades\CustomFields;

trait HasCustomFieldColumns
{
    use HasColumnMenu;
    use HasResizableColumns;
    use HasTableLayouts;
    use PersistsTableState;

    public function table(Table $table): Table
    {
        $builder = CustomFields::table()->forModel($this->getModel());

        $fields = $builder->getFields()->keyBy(fn (CustomField $field): string => $field->getFieldName());

        $columns = $builder->columns()
            ->map(function (Column $column) use ($fields): Column {
                $field = $fields->get($column->getName());

                return $field instanceof CustomField
                    ? $column->label(fn (): Htmlable => ColumnHeaderLabel::make($this->tableColumnLabel($column->getName(), $field->name), $field->typeData->icon))
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

            $column->label(fn (): Htmlable => ColumnHeaderLabel::make($this->tableColumnLabel($column->getName(), $label), $icon));
        }

        return $table
            ->modifyQueryUsing(function (Builder $query): void {
                $query->with('customFieldValues.customField');
            })
            ->deferFilters(false)
            ->pushColumns($columns)
            ->pushFilters($builder->filters()->all())
            ->resizableColumns();
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
