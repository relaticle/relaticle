<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Opportunity;

use App\Http\Resources\V1\OpportunityResource;
use App\Models\Opportunity;
use Relaticle\Chat\Tools\BaseReadShowTool;

final class GetOpportunityTool extends BaseReadShowTool
{
    public function description(): string
    {
        return 'Get a single opportunity/deal by ID with full details.';
    }

    protected function modelClass(): string
    {
        return Opportunity::class;
    }

    protected function resourceClass(): string
    {
        return OpportunityResource::class;
    }

    protected function entityLabel(): string
    {
        return 'Opportunity';
    }
}
