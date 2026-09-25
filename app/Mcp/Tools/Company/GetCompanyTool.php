<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\BaseShowTool;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Get Company')]
#[Description('Get a single company by ID with full details and relationships.')]
final class GetCompanyTool extends BaseShowTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Company;
    }

    /** @return class-string<JsonResource> */
    protected function resourceClass(): string
    {
        return CompanyResource::class;
    }

    /** @return array<int, string> */
    protected function allowedIncludes(): array
    {
        return ['creator', 'accountOwner', 'people', 'opportunities', 'tasks', 'notes', 'peopleCount', 'opportunitiesCount', 'tasksCount', 'notesCount'];
    }
}
