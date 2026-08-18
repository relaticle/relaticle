<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\ListCompanies;
use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\BaseListTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List companies in the CRM with optional search and pagination.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class ListCompaniesTool extends BaseListTool
{
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

    protected function additionalSchema(JsonSchema $schema): array
    {
        return [
            'created_after' => $schema->string()->description('Only return records created on or after this date (YYYY-MM-DD).'),
            'created_before' => $schema->string()->description('Only return records created on or before this date (YYYY-MM-DD).'),
        ];
    }

    protected function additionalFilters(Request $request): array
    {
        return [
            'created_after' => $request->get('created_after'),
            'created_before' => $request->get('created_before'),
        ];
    }
}
