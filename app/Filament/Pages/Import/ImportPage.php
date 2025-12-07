<?php

declare(strict_types=1);

namespace App\Filament\Pages\Import;

use BackedEnum;
use Filament\Pages\Page;
use Override;
use UnitEnum;

/**
 * Base import page that embeds the import wizard for a specific entity type.
 */
abstract class ImportPage extends Page
{
    protected string $view = 'filament.pages.import.import-page';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|null|UnitEnum $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Get the entity type key (e.g., 'companies', 'people').
     */
    abstract public static function getEntityType(): string;

    /**
     * Get the resource class to redirect back to after import.
     *
     * @return class-string
     */
    abstract public static function getResourceClass(): string;

    /**
     * Get the entity label (e.g., 'Companies', 'People').
     */
    public static function getEntityLabel(): string
    {
        return str(static::getEntityType())->title()->toString();
    }

    #[Override]
    public function getTitle(): string
    {
        return 'Import ' . static::getEntityLabel();
    }

    #[Override]
    public function getSubheading(): string
    {
        return 'Import ' . strtolower(static::getEntityLabel()) . ' from a CSV or Excel file';
    }

    /**
     * Get breadcrumbs for navigation context.
     *
     * @return array<string, string>
     */
    public function getBreadcrumbs(): array
    {
        $resourceClass = static::getResourceClass();

        $breadcrumbs = [];

        if (method_exists($resourceClass, 'getUrl')) {
            $breadcrumbs[$resourceClass::getUrl()] = static::getEntityLabel();
        }

        $breadcrumbs[] = 'Import';

        return $breadcrumbs;
    }

    /**
     * Get the URL to redirect to after import completion.
     */
    public function getReturnUrl(): string
    {
        $resourceClass = static::getResourceClass();

        return $resourceClass::getUrl();
    }
}
