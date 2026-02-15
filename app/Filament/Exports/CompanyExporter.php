<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Company;
use Carbon\Carbon;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Models\Export;
use Relaticle\CustomFields\Facades\CustomFields;

final class CompanyExporter extends BaseExporter
{
    protected static ?string $model = Company::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),
            ExportColumn::make('name')
                ->label('Company Name'),
            ExportColumn::make('team.name')
                ->label('Team'),
            ExportColumn::make('accountOwner.name')
                ->label('Account Owner'),
            ExportColumn::make('creator.name')
                ->label('Created By'),
            ExportColumn::make('people_count')
                ->label('Number of People')
                ->state(fn (Company $company): int => $company->people()->count()),
            ExportColumn::make('opportunities_count')
                ->label('Number of Opportunities')
                ->state(fn (Company $company): int => $company->opportunities()->count()),
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
        $body = 'Your company export has completed and '.number_format($successfulRows).' '.str('row')->plural($successfulRows).' exported.';

        if (($failedRowsCount = $export->getFailedRowsCount()) !== 0) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
