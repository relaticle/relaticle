<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Actions\Opportunity\ListOpportunities;
use App\Http\Resources\V1\OpportunityResource;
use App\Mcp\Tools\BaseListTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List opportunities (deals) in the CRM with optional search and pagination.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListOpportunitiesTool extends BaseListTool
{
    protected function actionClass(): string
    {
        return ListOpportunities::class;
    }

    protected function resourceClass(): string
    {
        return OpportunityResource::class;
    }

    protected function searchFilterName(): string
    {
        return 'name';
    }

    protected function additionalSchema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('Filter by company ID.'),
            'contact_id' => $schema->string()->description('Filter by contact (person) ID.'),
        ];
    }

    protected function additionalFilters(Request $request): array
    {
        return [
            'company_id' => $request->get('company_id'),
            'contact_id' => $request->get('contact_id'),
        ];
    }
}
