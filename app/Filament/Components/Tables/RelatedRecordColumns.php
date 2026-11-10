<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use App\Enums\CustomFields\PeopleField;
use App\Enums\CustomFields\TaskField;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\Task;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Facades\CustomFields;

final class RelatedRecordColumns
{
    private const array SEARCHABLE_FIELDS = [PeopleField::JOB_TITLE, PeopleField::EMAILS];

    /**
     * @return array<int, Column>
     */
    public static function people(): array
    {
        return [
            RecordChipColumn::make('name')
                ->searchable(),
            ...self::customFields(People::class, [PeopleField::JOB_TITLE, PeopleField::EMAILS]),
        ];
    }

    /**
     * @return array<int, Column>
     */
    public static function tasks(): array
    {
        return [
            TextColumn::make('title')
                ->searchable(),
            ...self::customFields(Task::class, [TaskField::STATUS, TaskField::DUE_DATE]),
            RecordChipColumn::make('assignees.name')
                ->label(__('filament/resources/task.fields.assignees.label'))
                ->collapsed()
                ->searchable(),
        ];
    }

    /**
     * @return array<int, Column>
     */
    public static function notes(): array
    {
        return [
            TextColumn::make('title')
                ->searchable(),
            TextColumn::make('created_at')
                ->label(__('filament/resources/note.fields.created_at.label'))
                ->dateTime()
                ->sortable(),
        ];
    }

    /**
     * @param  class-string<Model>  $model
     * @param  array<int, PeopleField|TaskField>  $fields
     * @return array<int, Column>
     */
    private static function customFields(string $model, array $fields): array
    {
        $codes = array_map(fn (PeopleField|TaskField $field): string => $field->value, $fields);
        $builder = CustomFields::table()->forModel($model)->only($codes);
        $definitions = $builder->getFields()->keyBy('code');

        return $builder->columns()
            ->map(fn (Column $column): Column => self::withFixedSearch($column->toggleable(false), $definitions))
            ->all();
    }

    /**
     * @param  Collection<string, CustomField>  $definitions
     */
    private static function withFixedSearch(Column $column, Collection $definitions): Column
    {
        $code = Str::after($column->getName(), 'custom_fields.');
        $definition = $definitions->get($code);

        if (! $definition instanceof CustomField) {
            return $column;
        }

        $searchableCodes = array_map(fn (PeopleField $field): string => $field->value, self::SEARCHABLE_FIELDS);

        return $column->searchable(
            in_array($code, $searchableCodes, true),
            fn (Builder $query, string $search): Builder => $query->whereHas('customFieldValues', static function (Builder $values) use ($definition, $search): void {
                /** @var Builder<CustomFieldValue> $values */
                $values->containingText($definition, $search);
            }),
        );
    }
}
