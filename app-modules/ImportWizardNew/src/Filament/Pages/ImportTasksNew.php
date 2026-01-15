<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Filament\Pages;

use App\Filament\Resources\TaskResource;
use Relaticle\ImportWizardNew\Enums\ImportEntityType;

/**
 * Import page for tasks using the new import wizard.
 */
final class ImportTasksNew extends ImportPage
{
    protected static ?string $slug = 'import-tasks-new';

    public static function getEntityType(): ImportEntityType
    {
        return ImportEntityType::Task;
    }

    public static function getResourceClass(): string
    {
        return TaskResource::class;
    }
}
