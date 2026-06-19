<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Opportunity;

use App\Actions\Opportunity\ListOpportunities;
use App\Http\Resources\V1\OpportunityResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseReadListTool;

final class ListOpportunitiesTool extends BaseReadListTool
{
    public function description(): string
    {
        return 'List opportunities/deals with optional search and filters.';
    }

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

    /** @return array<string, mixed> */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('Filter by company ID.'),
            'contact_id' => $schema->string()->description('Filter by contact/person ID.'),
            'created_after' => $schema->string()->description('Only return records created on or after this date (YYYY-MM-DD).'),
            'created_before' => $schema->string()->description('Only return records created on or before this date (YYYY-MM-DD).'),
        ];
    }

    /** @return array<string, mixed> */
    protected function additionalFilters(Request $request): array
    {
        return array_filter([
            'company_id' => $request['company_id'] ?? null,
            'contact_id' => $request['contact_id'] ?? null,
            'created_after' => $request['created_after'] ?? null,
            'created_before' => $request['created_before'] ?? null,
        ]);
    }

    protected function citationType(): string
    {
        return 'opportunity';
    }
}
