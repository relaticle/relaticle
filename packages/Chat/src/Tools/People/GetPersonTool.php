<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\People;

use App\Http\Resources\V1\NoteResource;
use App\Http\Resources\V1\PeopleResource;
use App\Http\Resources\V1\TaskResource;
use App\Models\People;
use Illuminate\Http\Resources\Json\JsonResource;
use Relaticle\Chat\Tools\BaseReadShowTool;

final class GetPersonTool extends BaseReadShowTool
{
    public function description(): string
    {
        return 'Get a single person/contact by ID with full details.';
    }

    protected function modelClass(): string
    {
        return People::class;
    }

    protected function resourceClass(): string
    {
        return PeopleResource::class;
    }

    protected function entityLabel(): string
    {
        return 'Person';
    }

    protected function citationType(): string
    {
        return 'people';
    }

    /** @return array<string, class-string<JsonResource>> */
    protected function availableIncludes(): array
    {
        return [
            'notes' => NoteResource::class,
            'tasks' => TaskResource::class,
        ];
    }
}
