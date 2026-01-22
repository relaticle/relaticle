<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use App\Filament\Resources\CompanyResource;
use Relaticle\ImportWizard\Enums\ImportEntityType;

/**
 * Import page for companies using the new import wizard.
 */
final class ImportCompaniesNew extends ImportPage
{
    protected static ?string $slug = 'import-companies-new';

    public static function getEntityType(): ImportEntityType
    {
        return ImportEntityType::Company;
    }

    public static function getResourceClass(): string
    {
        return CompanyResource::class;
    }
}
