<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Enums\CrmEntity;
use App\Filament\Components\Tables\ColumnHeaderLabel;
use App\Filament\Tables\Concerns\HasColumnMenu;
use App\Filament\Tables\Concerns\HasResizableColumns;
use App\Filament\Tables\Concerns\HasTableLayouts;
use App\Filament\Tables\Concerns\PersistsTableState;
use App\Models\CustomField;
use Filament\Support\Icons\Heroicon;
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

        $titleColumn = CrmEntity::tryFromModel(new ($this->getModel()))?->titleColumn();

        foreach ($table->getColumns() as $column) {
            $label = $column->getLabel();

            if ($column->getName() === $titleColumn) {
                continue;
            }

            if ($label instanceof Htmlable) {
                continue;
            }

            $column->label(fn (): Htmlable => ColumnHeaderLabel::make($this->tableColumnLabel($column->getName(), $label), self::nativeColumnIcon($column->getName())));
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

    private static function nativeColumnIcon(string $name): Heroicon
    {
        return match ($name) {
            'accountOwner.name' => Heroicon::OutlinedUser,
            'creator.name' => Heroicon::OutlinedUserCircle,
            'assignees.name' => Heroicon::OutlinedUsers,
            'company.name' => Heroicon::OutlinedBuildingOffice2,
            'relations' => Heroicon::OutlinedLink,
            'created_at' => Heroicon::OutlinedCalendar,
            'updated_at' => Heroicon::OutlinedClock,
            'deleted_at' => Heroicon::OutlinedTrash,
            default => Heroicon::OutlinedBars3BottomLeft,
        };
    }
}
