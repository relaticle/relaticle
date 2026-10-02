<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use App\Enums\CustomFields\PeopleField;
use App\Enums\CustomFields\TaskField;
use App\Models\People;
use App\Models\Task;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Facades\CustomFields;

final class RelatedRecordColumns
{
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
        return CustomFields::table()
            ->forModel($model)
            ->only(array_map(fn (PeopleField|TaskField $field): string => $field->value, $fields))
            ->columns()
            ->map(fn (Column $column): Column => $column->toggleable(false))
            ->all();
    }
}
