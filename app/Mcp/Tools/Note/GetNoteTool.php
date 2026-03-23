<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Http\Resources\V1\NoteResource;
use App\Mcp\Tools\BaseShowTool;
use App\Models\Note;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Get a single note by ID with full details and relationships.')]
final class GetNoteTool extends BaseShowTool
{
    protected function modelClass(): string
    {
        return Note::class;
    }

    /** @return class-string<JsonResource> */
    protected function resourceClass(): string
    {
        return NoteResource::class;
    }

    protected function entityLabel(): string
    {
        return 'Note';
    }

    /** @return array<int, string> */
    protected function allowedIncludes(): array
    {
        return ['creator', 'companies', 'people', 'opportunities', 'companiesCount', 'peopleCount', 'opportunitiesCount'];
    }
}
