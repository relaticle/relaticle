<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Pages;

use App\Filament\Resources\OpportunityResource;
use Relaticle\ImportWizard\Enums\ImportEntityType;

final class ImportOpportunities extends ImportPage
{
    protected static ?string $slug = 'opportunities/import';

    public static function getEntityType(): ImportEntityType
    {
        return ImportEntityType::Opportunity;
    }

    public static function getResourceClass(): string
    {
        return OpportunityResource::class;
    }
}
