<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Company\CreateCompany;
use App\Actions\Company\UpdateCompany;
use App\Http\Requests\Api\V1\UpsertCompanyRequest;
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
 *
 * Create a company, or update the one already holding the matched value. Returns 201 when
 * created and 200 when updated. Requires both the `create` and `update` abilities.
 */
final readonly class CompaniesUpsertController
{
    #[ResponseFromApiResource(CompanyResource::class, Company::class, status: 201)]
    #[Response(['message' => 'More than one record holds this domains value. Merge the duplicates, then retry.', 'matches' => ['01jz8x0m6v1b4n7q2r5t8w9y3a', '01jz8x0m6v1b4n7q2r5t8w9y3b']], 409, 'More than one company holds the matched value, so nothing was written. `matches` lists up to 25 of their IDs.')]
    #[BodyParam('match', 'object', 'The unique field and value that identify an existing company.', required: true, example: ['field' => 'domains', 'value' => 'acme.com'])]
    #[BodyParam('match.field', 'string', 'Code of a custom attribute (custom field) marked unique, such as `domains`.', required: true, example: 'domains')]
    #[BodyParam('match.value', 'string', 'Value to look for. Matched case-insensitively, and inside multi-value fields.', required: true, example: 'acme.com')]
    #[BodyParam('name', 'string', required: true, example: 'Acme Corp')]
    public function __invoke(
        UpsertCompanyRequest $request,
        CreateCompany $createCompany,
        UpdateCompany $updateCompany,
        #[CurrentUser] User $user,
    ): JsonResponse {
        return $request->whileHoldingMatch(function () use ($request, $createCompany, $updateCompany, $user): JsonResponse {
            $company = $request->matchedCompany();
            $data = $request->upsertData($company);

            if ($company instanceof Company) {
                return new CompanyResource($updateCompany->execute($user, $company, $data))->response();
            }

            return new CompanyResource($createCompany->execute($user, $data))
                ->response()
                ->setStatusCode(201);
        });
    }
}
