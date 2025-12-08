<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use App\Filament\Resources\PeopleResource;

final class ImportPeople extends ImportPage
{
    protected static ?string $slug = 'people/import';

    public static function getEntityType(): string
    {
        return 'people';
    }

    public static function getResourceClass(): string
    {
        return PeopleResource::class;
    }

    public static function getEntityLabel(): string
    {
        return 'People';
    }
}
