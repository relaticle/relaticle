<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\ListCompanies;
use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\BaseListTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List companies in the CRM with optional search and pagination.')]
#[IsReadOnly]
#[IsIdempotent]
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
}
