<?php

declare(strict_types=1);

namespace App\Filament\Resources\CompanyResource\Pages;

use App\Filament\Resources\CompanyResource;
use Relaticle\EmailIntegration\Filament\Pages\BaseRecordEmailsPage;

final class CompanyEmailsPage extends BaseRecordEmailsPage
{
    protected static string $resource = CompanyResource::class;
}
