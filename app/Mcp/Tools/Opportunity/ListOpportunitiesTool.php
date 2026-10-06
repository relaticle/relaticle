<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Enums\CrmEntity;
use App\Http\Resources\V1\OpportunityResource;
use App\Mcp\Tools\BaseListTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('List Opportunities')]
#[Description('List opportunities (deals) in the CRM with optional filters and pagination.')]
final class ListOpportunitiesTool extends BaseListTool
{
    protected function entity(): CrmEntity
    {
        return CrmEntity::Opportunity;
    }

    protected function resourceClass(): string
    {
        return OpportunityResource::class;
    }
}
