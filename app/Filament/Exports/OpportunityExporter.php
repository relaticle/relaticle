<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Opportunity;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Relaticle\CustomFields\Facades\CustomFields;

final class OpportunityExporter extends Exporter
{
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),
            ExportColumn::make('name')
                ->label('Opportunity Name'),
            ExportColumn::make('company.name')
                ->label('Company'),
            ExportColumn::make('contact.name')
                ->label('Contact Person'),
            ExportColumn::make('team.name')
                ->label('Team'),
            ExportColumn::make('creator.name')
                ->label('Created By'),
            ExportColumn::make('notes_count')
                ->label('Number of Notes')
                ->state(fn (Opportunity $opportunity): int => $opportunity->notes()->count()),
            ExportColumn::make('tasks_count')
                ->label('Number of Tasks')
                ->state(fn (Opportunity $opportunity): int => $opportunity->tasks()->count()),
            ExportColumn::make('created_at')
                ->label('Created At')
                ->formatStateUsing(fn (Carbon $state): string => $state->format('Y-m-d H:i:s')),
            ExportColumn::make('updated_at')
                ->label('Updated At')
                ->formatStateUsing(fn (Carbon $state): string => $state->format('Y-m-d H:i:s')),
            ExportColumn::make('creation_source')
                ->label('Creation Source')
                ->formatStateUsing(fn (mixed $state): string => $state->value ?? (string) $state),

            // Add all custom fields automatically
            ...CustomFields::exporter()->forModel(self::getModel())->columns(),
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
