<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Resources\CompanyResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Relaticle\SystemAdmin\Filament\Resources\CompanyResource;

final class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;
}
