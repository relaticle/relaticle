<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Pages\BaseRecordEmailsPage;
use App\Filament\Resources\CompanyResource;

final class CompanyEmailsPage extends BaseRecordEmailsPage
{
    protected static string $resource = CompanyResource::class;
}
