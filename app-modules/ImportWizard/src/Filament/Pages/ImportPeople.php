<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use App\Filament\Resources\PeopleResource;
use Relaticle\ImportWizard\Enums\ImportEntityType;

final class ImportPeople extends ImportPage
{
    protected static ?string $slug = 'people/import';

    public static function getEntityType(): ImportEntityType
    {
        return ImportEntityType::People;
    }

    public static function getResourceClass(): string
    {
        return PeopleResource::class;
    }
}
