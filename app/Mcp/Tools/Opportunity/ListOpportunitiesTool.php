<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Actions\Opportunity\ListOpportunities;
use App\Http\Resources\V1\OpportunityResource;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List opportunities (deals) in the CRM with optional search and pagination.')]
#[IsReadOnly]
#[IsIdempotent]
final class ListOpportunitiesTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Search opportunities by name.'),
            'company_id' => $schema->string()->description('Filter by company ID.'),
            'per_page' => $schema->integer()->description('Results per page (default 15, max 100).')->default(15),
            'page' => $schema->integer()->description('Page number.')->default(1),
        ];
    }

    public function handle(Request $request, ListOpportunities $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $query = [];

        if ($search = $request->get('search')) {
            $query['filter']['name'] = $search;
        }

        if ($companyId = $request->get('company_id')) {
            $query['filter']['company_id'] = $companyId;
        }

        if ($page = $request->get('page')) {
            $query['page'] = $page;
        }

        if ($perPage = $request->get('per_page')) {
            $query['per_page'] = $perPage;
        }

        request()->merge($query);

        $opportunities = $action->execute($user, (int) $request->get('per_page', 15));

        return Response::text(
            OpportunityResource::collection($opportunities)->toJson(JSON_PRETTY_PRINT)
        );
    }
}
