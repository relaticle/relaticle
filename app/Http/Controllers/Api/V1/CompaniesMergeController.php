<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Merge\MergeRecords;
use App\Http\Requests\Api\V1\MergeRecordsRequest;
use App\Http\Resources\V1\CompanyResource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;

/**
 * @group Companies
 */
final readonly class CompaniesMergeController
{
    /**
     * Merge two companies
     *
     * Creates a new company from the two. The primary company wins every conflict and the secondary fills its empty values.
     * Links, notes, tasks and history move to the new company, and both originals answer 404 afterwards.
     * The token needs the `create`, `update` and `delete` abilities.
     */
    #[ResponseFromApiResource(CompanyResource::class, Company::class)]
    #[Response(['message' => 'The secondary record id field and primary record id must be different.', 'errors' => ['secondary_record_id' => ['The secondary record id field and primary record id must be different.']]], 422, 'An id is missing, is not a ULID, repeats the other id, or names a company that is unknown, deleted, already merged or in another workspace. Merged multi-value fields that pass 20 values fail on `records`.')]
    #[BodyParam('primary_record_id', 'string', 'The company that wins every conflict.', required: true, example: '01jz8x0m6v1b4n7q2r5t8w9y3a')]
    #[BodyParam('secondary_record_id', 'string', 'The company merged into it. Must differ from the primary.', required: true, example: '01jz8x0m6v1b4n7q2r5t8w9y3b')]
    public function __invoke(MergeRecordsRequest $request, MergeRecords $mergeRecords, #[CurrentUser] User $user): JsonResponse
    {
        [$primary, $secondary] = $request->records();

        $merged = $mergeRecords->execute($user, $primary, $secondary);
        $merged->loadMissing('customFieldValues.customField.options');

        return new CompanyResource($merged)->response()->setStatusCode(200);
    }
}
