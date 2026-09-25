<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\NoteResource;
use App\Mcp\Tools\BaseShowTool;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Get Note')]
#[Description('Get a single note by ID with full details and relationships.')]
final class GetNoteTool extends BaseShowTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Note;
    }

    /** @return class-string<JsonResource> */
    protected function resourceClass(): string
    {
        return NoteResource::class;
    }

    /** @return array<int, string> */
    protected function allowedIncludes(): array
    {
        return ['creator', 'companies', 'people', 'opportunities', 'companiesCount', 'peopleCount', 'opportunitiesCount'];
    }
}
