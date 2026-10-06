<?php

declare(strict_types=1);

namespace App\Filament\Resources\TaskResource\Forms;

use App\Enums\CustomFields\TaskField;
use App\Filament\Components\Forms\LinkedRecordsSelect;
use App\Filament\Components\Forms\WorkspaceMemberSelect;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Relaticle\CustomFields\Facades\CustomFields;

final class TaskForm
{
    /**
     * @param  array<string>  $excludeFields
     *
     * @throws \Exception
     */
    public static function get(Schema $schema, array $excludeFields = []): Schema
    {
        $propertyCodes = [TaskField::STATUS->value, TaskField::PRIORITY->value, TaskField::DUE_DATE->value];
        $placedCodes = [...$propertyCodes, TaskField::DESCRIPTION->value];

        return $schema
            ->components([
                TextInput::make('title')
                    ->required(),
                self::customFieldRow(array_values(array_diff($propertyCodes, $excludeFields))),
                Grid::make(['default' => 1, 'sm' => 3])
                    ->schema([
                        WorkspaceMemberSelect::make('assignees')
                            ->label(__('filament/resources/task.fields.assignees.label'))
                            ->multiple()
                            ->relationship('assignees', 'name')
                            ->nullable(),
                        LinkedRecordsSelect::make('relations')
                            ->withoutRelations($excludeFields)
                            ->columnSpan(['default' => 1, 'sm' => 2]),
                    ]),
                CustomFields::form()->only([TaskField::DESCRIPTION->value])->except($excludeFields)->build(),
                CustomFields::form()->except([...$placedCodes, ...$excludeFields])->build(),
            ])
            ->columns(1);
    }

    /**
     * @param  array<int, string>  $codes
     */
    private static function customFieldRow(array $codes): Grid
    {
        return Grid::make(['default' => 1, 'sm' => max(count($codes), 1)])
            ->schema(array_map(
                fn (string $code): Grid => CustomFields::form()->only([$code])->build(),
                $codes,
            ));
    }
}
