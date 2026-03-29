<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\People\CreatePeople;
use App\Actions\People\DeletePeople;
use App\Actions\People\ListPeople;
use App\Actions\People\UpdatePeople;
use App\Enums\CreationSource;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\StorePeopleRequest;
use App\Http\Requests\Api\V1\UpdatePeopleRequest;
use App\Http\Resources\V1\PeopleResource;
use App\Models\People;
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
 * @group People
 *
 * Manage people (contacts) in your CRM workspace.
 */
final readonly class PeopleController
{
    #[ResponseFromApiResource(PeopleResource::class, People::class, collection: true, paginate: 15)]
    public function index(IndexRequest $request, ListPeople $action, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        return PeopleResource::collection($action->execute(
            user: $user,
            perPage: $request->safe()->integer('per_page', 15),
            useCursor: $request->safe()->has('cursor'),
            request: $request,
        ));
    }

    #[ResponseFromApiResource(PeopleResource::class, People::class, status: 201)]
    #[BodyParam('name', 'string', required: true, example: 'Jane Smith')]
    #[BodyParam('company_id', 'string', required: false, example: null)]
    public function store(StorePeopleRequest $request, CreatePeople $action, #[CurrentUser] User $user): JsonResponse
    {
        $person = $action->execute($user, $request->validated(), CreationSource::API);

        return new PeopleResource($person)
            ->response()
            ->setStatusCode(201);
    }

    #[ResponseFromApiResource(PeopleResource::class, People::class)]
    public function show(People $person): PeopleResource
    {
        Gate::authorize('view', $person);

        $person->loadMissing('customFieldValues.customField.options');

        return new PeopleResource($person);
    }

    #[ResponseFromApiResource(PeopleResource::class, People::class)]
    #[BodyParam('name', 'string', required: false, example: 'Jane Smith')]
    #[BodyParam('company_id', 'string', required: false, example: null)]
    public function update(UpdatePeopleRequest $request, People $person, UpdatePeople $action, #[CurrentUser] User $user): PeopleResource
    {
        $person = $action->execute($user, $person, $request->validated());

        return new PeopleResource($person);
    }

    #[Response(status: 204)]
    public function destroy(People $person, DeletePeople $action, #[CurrentUser] User $user): HttpResponse
    {
        $action->execute($user, $person);

        return response()->noContent();
    }
}
