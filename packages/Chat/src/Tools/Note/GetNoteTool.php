<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Note;

use App\Http\Resources\V1\NoteResource;
use App\Models\Note;
use Relaticle\Chat\Tools\BaseReadShowTool;

final class GetNoteTool extends BaseReadShowTool
{
    public function description(): string
    {
        return 'Get a single note by ID with full details.';
    }

    protected function modelClass(): string
    {
        return Note::class;
    }

    protected function resourceClass(): string
    {
        return NoteResource::class;
    }

    protected function entityLabel(): string
    {
        return 'Note';
    }
}
