<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\PeopleResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Relaticle\SystemAdmin\Filament\Resources\PeopleResource;

final class CreatePeople extends CreateRecord
{
    protected static string $resource = PeopleResource::class;
}
