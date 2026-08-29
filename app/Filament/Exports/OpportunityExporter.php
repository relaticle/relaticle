<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Opportunity;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;
use Relaticle\CustomFields\Facades\CustomFields;

final class OpportunityExporter extends BaseExporter
{
    protected static ?string $model = Opportunity::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label(__('filament/exports.columns.id')),
            ExportColumn::make('name')
                ->label(__('filament/exports.columns.opportunity_name')),
            ExportColumn::make('company.name')
                ->label(__('filament/exports.columns.company')),
            ExportColumn::make('contact.name')
                ->label(__('filament/exports.columns.contact_person')),
            ExportColumn::make('team.name')
                ->label(__('filament/exports.columns.team')),
            ExportColumn::make('creator.name')
                ->label(__('filament/exports.columns.creator')),
            ExportColumn::make('notes_count')
                ->label(__('filament/exports.columns.notes_count'))
                ->state(fn (Opportunity $opportunity): int => $opportunity->notes()->count()),
            ExportColumn::make('tasks_count')
                ->label(__('filament/exports.columns.tasks_count'))
                ->state(fn (Opportunity $opportunity): int => $opportunity->tasks()->count()),
            self::dateTimeColumn('created_at', __('filament/exports.columns.created_at')),
            self::dateTimeColumn('updated_at', __('filament/exports.columns.updated_at')),
            ExportColumn::make('creation_source')
                ->label(__('filament/exports.columns.creation_source'))
                ->formatStateUsing(fn (mixed $state): string => $state->value ?? (string) $state),

            // Add all custom fields automatically
            ...self::customFieldColumns(CustomFields::exporter()->forModel(self::getModel())->columns()),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $successfulRows = $export->successful_rows ?? 0;
        $body = 'Your opportunity export has completed and '.number_format($successfulRows).' '.str('row')->plural($successfulRows).' exported.';

        if (($failedRowsCount = $export->getFailedRowsCount()) !== 0) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
