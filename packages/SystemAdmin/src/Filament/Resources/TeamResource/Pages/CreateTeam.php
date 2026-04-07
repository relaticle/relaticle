<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;

final class CreateTeam extends CreateRecord
{
    protected static string $resource = TeamResource::class;
}
