<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use App\Filament\Resources\TaskResource;
use Relaticle\ImportWizard\Enums\ImportEntityType;

/**
 * Import page for tasks using the new import wizard.
 */
final class ImportTasksNew extends ImportPage
{
    protected static ?string $slug = 'import-tasks';

    public static function getEntityType(): ImportEntityType
    {
        return ImportEntityType::Task;
    }

    public static function getResourceClass(): string
    {
        return TaskResource::class;
    }
}
