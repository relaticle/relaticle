<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Http\Resources\V1\PeopleResource;
use App\Mcp\Tools\BaseShowTool;
use App\Models\People;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get a single person by ID with full details and relationships.')]
final class GetPeopleTool extends BaseShowTool
{
    protected function modelClass(): string
    {
        return People::class;
    }

    /** @return class-string<JsonResource> */
    protected function resourceClass(): string
    {
        return PeopleResource::class;
    }

    protected function entityLabel(): string
    {
        return 'Person';
    }

    /** @return array<int, string> */
    protected function allowedIncludes(): array
    {
        return ['creator', 'company', 'tasksCount', 'notesCount'];
    }
}
