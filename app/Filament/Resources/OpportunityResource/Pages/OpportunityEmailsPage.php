<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Pages;

use App\Filament\Pages\BaseRecordEmailsPage;
use App\Filament\Resources\OpportunityResource;

final class OpportunityEmailsPage extends BaseRecordEmailsPage
{
    protected static string $resource = OpportunityResource::class;
}
