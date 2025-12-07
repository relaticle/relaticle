<?php

declare(strict_types=1);

namespace App\Filament\Pages\Import;

use App\Filament\Resources\OpportunityResource;

final class ImportOpportunities extends ImportPage
{
    protected static ?string $slug = 'opportunities/import';

    public static function getEntityType(): string
    {
        return 'opportunities';
    }

    public static function getResourceClass(): string
    {
        return OpportunityResource::class;
    }
}
