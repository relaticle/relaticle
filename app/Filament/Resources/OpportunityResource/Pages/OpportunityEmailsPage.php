<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Pages;

use App\Filament\Resources\OpportunityResource;
use Relaticle\EmailIntegration\Filament\Pages\BaseRecordEmailsPage;

final class OpportunityEmailsPage extends BaseRecordEmailsPage
{
    protected static string $resource = OpportunityResource::class;
}
