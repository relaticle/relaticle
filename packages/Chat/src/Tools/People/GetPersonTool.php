<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\People;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\NoteResource;
use App\Http\Resources\V1\PeopleResource;
use App\Http\Resources\V1\TaskResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Relaticle\Chat\Tools\BaseReadShowTool;

final class GetPersonTool extends BaseReadShowTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Get a single person/contact by ID with full details.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
    }

    protected function resourceClass(): string
    {
        return PeopleResource::class;
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
