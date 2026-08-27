<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Actions\Opportunity\ListOpportunities;
use App\Http\Resources\V1\OpportunityResource;
use App\Mcp\Tools\BaseListTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('List Opportunities')]
#[Description('List opportunities (deals) in the CRM with optional search and pagination.')]
final class ListOpportunitiesTool extends BaseListTool
{
    private const int MAX_STALE_DAYS = 3650;

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
            'stale_days' => $schema->integer()->min(1)->max(self::MAX_STALE_DAYS)->description('Return only opportunities with no activity in the last N days. Use this to find deals that have gone quiet.'),
        ];
    }

    protected function additionalFilters(Request $request): array
    {
        return [
            'company_id' => $request->get('company_id'),
            'contact_id' => $request->get('contact_id'),
            'stale_days' => $request->get('stale_days') !== null ? (string) $request->get('stale_days') : null,
        ];
    }

    protected function additionalValidationRules(User $user): array
    {
        return [
            'company_id' => ['sometimes', 'string', 'ulid'],
            'contact_id' => ['sometimes', 'string', 'ulid'],
            'stale_days' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_STALE_DAYS],
        ];
    }
}
