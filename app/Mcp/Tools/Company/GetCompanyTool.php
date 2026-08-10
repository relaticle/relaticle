<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\BaseShowTool;
use App\Models\Company;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Get a single company by ID with full details and relationships.')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class GetCompanyTool extends BaseShowTool
{
    protected function modelClass(): string
    {
        return Company::class;
    }

    /** @return class-string<JsonResource> */
    protected function resourceClass(): string
    {
        return CompanyResource::class;
    }

    protected function entityLabel(): string
    {
        return 'Company';
    }

    /** @return array<int, string> */
    protected function allowedIncludes(): array
    {
        return ['creator', 'accountOwner', 'people', 'opportunities', 'peopleCount', 'opportunitiesCount', 'tasksCount', 'notesCount'];
    }
}
