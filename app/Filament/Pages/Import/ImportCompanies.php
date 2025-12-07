<?php

declare(strict_types=1);

namespace App\Filament\Pages\Import;

use App\Filament\Resources\CompanyResource;

final class ImportCompanies extends ImportPage
{
    protected static ?string $slug = 'companies/import';

    public static function getEntityType(): string
    {
        return 'companies';
    }

    public static function getResourceClass(): string
    {
        return CompanyResource::class;
    }
}
