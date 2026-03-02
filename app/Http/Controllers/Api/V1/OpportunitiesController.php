<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Opportunity\CreateOpportunity;
use App\Actions\Opportunity\DeleteOpportunity;
use App\Actions\Opportunity\ListOpportunities;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Enums\CreationSource;
use App\Http\Requests\Api\V1\StoreOpportunityRequest;
use App\Http\Requests\Api\V1\UpdateOpportunityRequest;
use App\Http\Resources\V1\OpportunityResource;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
    public function index(Request $request, ListOpportunities $action): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return OpportunityResource::collection($action->execute($user));
    }

    #[ResponseFromApiResource(OpportunityResource::class, Opportunity::class, status: 201)]
    #[BodyParam('name', 'string', required: true, example: 'Enterprise Deal')]
    #[BodyParam('company_id', 'string', required: false, example: null)]
    #[BodyParam('contact_id', 'string', required: false, example: null)]
    public function store(StoreOpportunityRequest $request, CreateOpportunity $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $opportunity = $action->execute($user, $request->validated(), CreationSource::API);

        return new OpportunityResource($opportunity->load('customFieldValues.customField'))
            ->response()
            ->setStatusCode(201);
    }

    #[ResponseFromApiResource(OpportunityResource::class, Opportunity::class)]
    public function show(Opportunity $opportunity): OpportunityResource
    {
        Gate::authorize('view', $opportunity);

        $opportunity->loadMissing('customFieldValues.customField');

        return new OpportunityResource($opportunity);
    }

    #[ResponseFromApiResource(OpportunityResource::class, Opportunity::class)]
    #[BodyParam('name', 'string', required: false, example: 'Enterprise Deal')]
    #[BodyParam('company_id', 'string', required: false, example: null)]
    #[BodyParam('contact_id', 'string', required: false, example: null)]
    public function update(UpdateOpportunityRequest $request, Opportunity $opportunity, UpdateOpportunity $action): OpportunityResource
    {
        /** @var User $user */
        $user = $request->user();

        $opportunity = $action->execute($user, $opportunity, $request->validated());

        return new OpportunityResource($opportunity->load('customFieldValues.customField'));
    }

    #[Response(status: 204)]
    public function destroy(Request $request, Opportunity $opportunity, DeleteOpportunity $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user, $opportunity);

        return response()->json(null, 204);
    }
}
