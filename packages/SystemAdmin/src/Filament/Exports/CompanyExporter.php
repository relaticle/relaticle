<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Exports;

use App\Models\Company;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\CircularDependencyException;
use Relaticle\CustomFields\Facades\CustomFields;

final class CompanyExporter extends Exporter
{
    protected static ?string $model = Company::class;

    /**
     * @throws CircularDependencyException
     * @throws BindingResolutionException
     */
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')
                ->label('ID'),
            ExportColumn::make('team.name'),
            ExportColumn::make('creator.name'),
            ExportColumn::make('accountOwner.name'),
            ExportColumn::make('name'),
            ExportColumn::make('created_at'),
            ExportColumn::make('updated_at'),
            ExportColumn::make('deleted_at'),
            ExportColumn::make('creation_source'),

            // Add all custom fields automatically
            ...CustomFields::exporter()->forModel(self::getModel())->columns(),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your company export has completed and '.number_format($export->successful_rows).' '.str('row')->plural($export->successful_rows).' exported.';

        if (($failedRowsCount = $export->getFailedRowsCount()) !== 0) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to export.';
        }

        return $body;
    }
}
