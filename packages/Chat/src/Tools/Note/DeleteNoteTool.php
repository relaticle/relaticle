<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Note;

use App\Actions\Note\DeleteNote;
use App\Models\Note;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;

final class DeleteNoteTool extends BaseWriteDeleteTool
{
    public function description(): string
    {
        return 'Propose deleting a note. Returns a proposal for user approval.';
    }

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

    protected function entityType(): string
    {
        return 'note';
    }

    protected function nameAttribute(): string
    {
        return 'title';
    }
}
