<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\People;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\NoteResource;
use App\Http\Resources\V1\PeopleResource;
use App\Http\Resources\V1\TaskResource;
use Illuminate\Http\Resources\Json\JsonResource;
use Relaticle\Chat\Tools\BaseReadListTool;

final class ListPeopleTool extends BaseReadListTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'List people/contacts in the CRM with optional filters and pagination.';
    }

    protected function resourceClass(): string
    {
        return PeopleResource::class;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::People;
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
