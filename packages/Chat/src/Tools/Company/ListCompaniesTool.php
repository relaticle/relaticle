<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Company;

use App\Actions\Company\ListCompanies;
use App\Http\Resources\V1\CompanyResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseReadListTool;

final class ListCompaniesTool extends BaseReadListTool
{
    public function description(): string
    {
        return 'List companies in the CRM with optional search and pagination.';
    }

    protected function actionClass(): string
    {
        return ListCompanies::class;
    }

    protected function resourceClass(): string
    {
        return CompanyResource::class;
    }

    protected function searchFilterName(): string
    {
        return 'name';
    }

    /** @return array<string, mixed> */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [
            'created_after' => $schema->string()->description('Only return records created on or after this date (YYYY-MM-DD).'),
            'created_before' => $schema->string()->description('Only return records created on or before this date (YYYY-MM-DD).'),
        ];
    }

    /** @return array<string, mixed> */
    protected function additionalFilters(Request $request): array
    {
        return array_filter([
            'created_after' => $request['created_after'] ?? null,
            'created_before' => $request['created_before'] ?? null,
        ]);
    }

    protected function citationType(): string
    {
        return 'company';
    }
}
