<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\ConnectedAccountResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\SystemAdmin\Filament\Resources\ConnectedAccountResource;

final class ListConnectedAccounts extends ListRecords
{
    protected static string $resource = ConnectedAccountResource::class;
}
