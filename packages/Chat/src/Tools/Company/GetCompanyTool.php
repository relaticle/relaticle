<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Company;

use App\Http\Resources\V1\CompanyResource;
use App\Models\Company;
use Relaticle\Chat\Tools\BaseReadShowTool;

final class GetCompanyTool extends BaseReadShowTool
{
    public function description(): string
    {
        return 'Get a single company by ID with full details.';
    }

    protected function modelClass(): string
    {
        return Company::class;
    }

    protected function resourceClass(): string
    {
        return CompanyResource::class;
    }

    protected function entityLabel(): string
    {
        return 'Company';
    }
}
