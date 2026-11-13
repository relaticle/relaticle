<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\People\CreatePeople;
use App\Actions\People\UpdatePeople;
use App\Http\Requests\Api\V1\UpsertPeopleRequest;
use App\Http\Resources\V1\PeopleResource;
use App\Models\People;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;

/**
 * @group People
 *
 * Create a person, or update the one already holding the matched value. Returns 201 when
 * created and 200 when updated. Requires both the `create` and `update` abilities.
 */
final readonly class PeopleUpsertController
{
    #[ResponseFromApiResource(PeopleResource::class, People::class, status: 201)]
    #[Response(['message' => 'More than one record holds this emails value. Merge the duplicates, then retry.', 'matches' => ['01jz8x0m6v1b4n7q2r5t8w9y3a', '01jz8x0m6v1b4n7q2r5t8w9y3b']], 409, 'More than one person holds the matched value, so nothing was written. `matches` lists up to 25 of their IDs.')]
    #[BodyParam('match', 'object', 'The unique field and value that identify an existing person.', required: true, example: ['field' => 'emails', 'value' => 'grace@navy.mil'])]
    #[BodyParam('match.field', 'string', 'Code of a custom attribute (custom field) marked unique, such as `emails`.', required: true, example: 'emails')]
    #[BodyParam('match.value', 'string', 'Value to look for. Matched case-insensitively, and inside multi-value fields.', required: true, example: 'grace@navy.mil')]
    #[BodyParam('name', 'string', required: true, example: 'Grace Hopper')]
    #[BodyParam('company_id', 'string', required: false, example: null)]
    public function __invoke(
        UpsertPeopleRequest $request,
        CreatePeople $createPeople,
        UpdatePeople $updatePeople,
        #[CurrentUser] User $user,
    ): JsonResponse {
        return $request->whileHoldingMatch(function () use ($request, $createPeople, $updatePeople, $user): JsonResponse {
            $person = $request->matchedPerson();
            $data = $request->upsertData($person);

            if ($person instanceof People) {
                return new PeopleResource($updatePeople->execute($user, $person, $data))->response();
            }

            return new PeopleResource($createPeople->execute($user, $data))
                ->response()
                ->setStatusCode(201);
        });
    }
}
