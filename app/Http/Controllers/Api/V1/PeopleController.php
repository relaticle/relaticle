<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\People\CreatePeople;
use App\Actions\People\DeletePeople;
use App\Actions\People\ListPeople;
use App\Actions\People\UpdatePeople;
use App\Enums\CreationSource;
use App\Http\Requests\Api\V1\StorePeopleRequest;
use App\Http\Requests\Api\V1\UpdatePeopleRequest;
use App\Http\Resources\V1\PeopleResource;
use App\Models\People;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;

/**
 * @group People
 *
 * Manage people (contacts) in your CRM workspace.
 */
final readonly class PeopleController
{
    public function index(Request $request, ListPeople $action): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return PeopleResource::collection($action->execute($user));
    }

    #[ResponseFromApiResource(PeopleResource::class, People::class, status: 201)]
    #[BodyParam('name', 'string', required: true, example: 'Jane Smith')]
    #[BodyParam('company_id', 'string', required: false, example: null)]
    public function store(StorePeopleRequest $request, CreatePeople $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $person = $action->execute($user, $request->validated(), CreationSource::API);

        return new PeopleResource($person->load('customFieldValues.customField'))
            ->response()
            ->setStatusCode(201);
    }

    #[ResponseFromApiResource(PeopleResource::class, People::class)]
    public function show(People $person): PeopleResource
    {
        Gate::authorize('view', $person);

        $person->loadMissing('customFieldValues.customField');

        return new PeopleResource($person);
    }

    #[ResponseFromApiResource(PeopleResource::class, People::class)]
    #[BodyParam('name', 'string', required: false, example: 'Jane Smith')]
    #[BodyParam('company_id', 'string', required: false, example: null)]
    public function update(UpdatePeopleRequest $request, People $person, UpdatePeople $action): PeopleResource
    {
        /** @var User $user */
        $user = $request->user();

        $person = $action->execute($user, $person, $request->validated());

        return new PeopleResource($person->load('customFieldValues.customField'));
    }

    #[Response(status: 204)]
    public function destroy(Request $request, People $person, DeletePeople $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $action->execute($user, $person);

        return response()->json(null, 204);
    }
}
