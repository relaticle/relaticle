<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Enums\CrmEntity;
use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\BaseListTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('List Companies')]
#[Description('List companies in the CRM with optional filters and pagination.')]
final class ListCompaniesTool extends BaseListTool
{
    protected function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }

    protected function resourceClass(): string
    {
        return CompanyResource::class;
    }
}
