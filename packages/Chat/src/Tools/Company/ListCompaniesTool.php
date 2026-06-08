<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Company;

use App\Actions\Company\ListCompanies;
use App\Http\Resources\V1\CompanyResource;
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
}
