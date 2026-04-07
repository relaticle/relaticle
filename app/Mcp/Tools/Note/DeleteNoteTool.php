<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Actions\Note\DeleteNote;
use App\Mcp\Tools\BaseDeleteTool;
use App\Models\Note;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Delete a note from the CRM (soft delete).')]
#[IsDestructive]
final class DeleteNoteTool extends BaseDeleteTool
{
    protected function modelClass(): string
    {
        return Note::class;
    }

    protected function actionClass(): string
    {
        return DeleteNote::class;
    }

    protected function entityLabel(): string
    {
        return 'Note';
    }

    protected function nameAttribute(): string
    {
        return 'title';
    }
}
