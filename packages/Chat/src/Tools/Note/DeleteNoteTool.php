<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Note;

use App\Actions\Note\DeleteNote;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;

final class DeleteNoteTool extends BaseWriteDeleteTool
{
    use OperatesOnCrmEntity;

    public function description(): string
    {
        return 'Propose deleting a note. Returns a proposal for user approval.';
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Note;
    }

    protected function actionClass(): string
    {
        return DeleteNote::class;
    }
}
