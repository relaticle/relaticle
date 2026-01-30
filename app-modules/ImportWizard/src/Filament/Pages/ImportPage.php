<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Override;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use UnitEnum;

/**
 * Base import page that embeds the new import wizard for a specific entity type.
 */
abstract class ImportPage extends Page
{
    protected string $view = 'import-wizard-new::filament.pages.import-page';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static string|null|UnitEnum $navigationGroup = null;

    protected static bool $shouldRegisterNavigation = false;

    /**
     * Check if the user can access the import page.
     *
     * Delegates to the resource's canCreate authorization if available.
     */
    public static function canAccess(): bool
    {
        $resourceClass = static::getResourceClass();

        if (method_exists($resourceClass, 'canCreate')) {
            return $resourceClass::canCreate();
        }

        return true;
    }

    /**
     * Get the entity type for this import page.
     */
    abstract public static function getEntityType(): ImportEntityType;

    /**
     * Get the resource class to redirect back to after import.
     *
     * @return class-string
     */
    abstract public static function getResourceClass(): string;

    #[Override]
    public function getTitle(): string
    {
        return 'Import '.static::getEntityType()->label();
    }

    #[Override]
    public function getSubheading(): string
    {
        return 'Import '.strtolower(static::getEntityType()->label()).' from a CSV file';
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
            $breadcrumbs[$resourceClass::getUrl()] = static::getEntityType()->label();
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
