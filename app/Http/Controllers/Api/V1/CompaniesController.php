<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Company\CreateCompany;
use App\Actions\Company\DeleteCompany;
use App\Actions\Company\ListCompanies;
use App\Actions\Company\UpdateCompany;
use App\Enums\CreationSource;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Http\Requests\Api\V1\StoreCompanyRequest;
use App\Http\Requests\Api\V1\UpdateCompanyRequest;
use App\Http\Resources\V1\CompanyResource;
use App\Models\Company;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Knuckles\Scribe\Attributes\Response;
use Knuckles\Scribe\Attributes\ResponseFromApiResource;

/**
 * @group Companies
 *
 * Manage companies in your CRM workspace.
 */
final readonly class CompaniesController
{
    #[ResponseFromApiResource(CompanyResource::class, Company::class, collection: true, paginate: 15)]
    public function index(IndexRequest $request, ListCompanies $action, #[CurrentUser] User $user): AnonymousResourceCollection
    {
        return CompanyResource::collection($action->execute(
            user: $user,
            perPage: $request->safe()->integer('per_page', 15),
            useCursor: $request->safe()->has('cursor'),
            request: $request,
        ));
    }

    #[ResponseFromApiResource(CompanyResource::class, Company::class, status: 201)]
    public function store(StoreCompanyRequest $request, CreateCompany $action, #[CurrentUser] User $user): JsonResponse
    {
        $company = $action->execute($user, $request->validated(), CreationSource::API);

        return new CompanyResource($company)
            ->response()
            ->setStatusCode(201);
    }

    #[ResponseFromApiResource(CompanyResource::class, Company::class)]
    public function show(Company $company): CompanyResource
    {
        Gate::authorize('view', $company);

        $company->loadMissing('customFieldValues.customField.options');

        return new CompanyResource($company);
    }

    #[ResponseFromApiResource(CompanyResource::class, Company::class)]
    public function update(UpdateCompanyRequest $request, Company $company, UpdateCompany $action, #[CurrentUser] User $user): CompanyResource
    {
        $company = $action->execute($user, $company, $request->validated());

        return new CompanyResource($company);
    }

    #[Response(status: 204)]
    public function destroy(Company $company, DeleteCompany $action, #[CurrentUser] User $user): HttpResponse
    {
        $action->execute($user, $company);

        return response()->noContent();
    }
}
