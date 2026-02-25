<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\ImportResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Relaticle\SystemAdmin\Filament\Resources\ImportResource;

final class ListImports extends ListRecords
{
    protected static string $resource = ImportResource::class;
}
