<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Opportunity\CreateOpportunity;
use App\Actions\Opportunity\DeleteOpportunity;
use App\Actions\Opportunity\ListOpportunities;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Enums\CreationSource;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\StoreOpportunityRequest;
use App\Http\Requests\Api\V1\UpdateOpportunityRequest;
use App\Http\Resources\V1\OpportunityResource;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;

/**
 * @group Opportunities
 *
 * Manage sales opportunities in your CRM workspace.
 */
final readonly class OpportunitiesController
{
    #[ResponseFromApiResource(OpportunityResource::class, Opportunity::class, collection: true, paginate: 15)]
    public function index(IndexRequest $request, ListOpportunities $action, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        return OpportunityResource::collection($action->execute(
            user: $user,
            perPage: $request->safe()->integer('per_page', 15),
            useCursor: $request->safe()->has('cursor'),
            request: $request,
        ));
    }

    #[ResponseFromApiResource(OpportunityResource::class, Opportunity::class, status: 201)]
    #[BodyParam('name', 'string', required: true, example: 'Enterprise Deal')]
    #[BodyParam('company_id', 'string', required: false, example: null)]
    #[BodyParam('contact_id', 'string', required: false, example: null)]
    public function store(StoreOpportunityRequest $request, CreateOpportunity $action, #[CurrentUser] User $user): JsonResponse
    {
        $opportunity = $action->execute($user, $request->validated(), CreationSource::API);

        return new OpportunityResource($opportunity)
            ->response()
            ->setStatusCode(201);
    }

    #[ResponseFromApiResource(OpportunityResource::class, Opportunity::class)]
    public function show(Opportunity $opportunity): OpportunityResource
    {
        Gate::authorize('view', $opportunity);

        $opportunity->loadMissing('customFieldValues.customField.options');

        return new OpportunityResource($opportunity);
    }

    #[ResponseFromApiResource(OpportunityResource::class, Opportunity::class)]
    #[BodyParam('name', 'string', required: false, example: 'Enterprise Deal')]
    #[BodyParam('company_id', 'string', required: false, example: null)]
    #[BodyParam('contact_id', 'string', required: false, example: null)]
    public function update(UpdateOpportunityRequest $request, Opportunity $opportunity, UpdateOpportunity $action, #[CurrentUser] User $user): OpportunityResource
    {
        $opportunity = $action->execute($user, $opportunity, $request->validated());

        return new OpportunityResource($opportunity);
    }

    #[Response(status: 204)]
    public function destroy(Opportunity $opportunity, DeleteOpportunity $action, #[CurrentUser] User $user): HttpResponse
    {
        $action->execute($user, $opportunity);

        return response()->noContent();
    }
}
