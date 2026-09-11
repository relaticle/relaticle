<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\PeopleResource;
use App\Mcp\Tools\BaseShowTool;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Get Person')]
#[Description('Get a single person by ID with full details and relationships.')]
final class GetPeopleTool extends BaseShowTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
    }

    /** @return class-string<JsonResource> */
    protected function resourceClass(): string
    {
        return PeopleResource::class;
    }

    /** @return array<int, string> */
    protected function allowedIncludes(): array
    {
        return ['creator', 'company', 'tasks', 'notes', 'tasksCount', 'notesCount'];
    }
}
