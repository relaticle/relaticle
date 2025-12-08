<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use App\Filament\Resources\TaskResource;

final class ImportTasks extends ImportPage
{
    protected static ?string $slug = 'tasks/import';

    public static function getEntityType(): string
    {
        return 'tasks';
    }

    public static function getResourceClass(): string
    {
        return TaskResource::class;
    }
}
