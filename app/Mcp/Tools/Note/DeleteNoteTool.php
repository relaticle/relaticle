<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Actions\Note\DeleteNote;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Mcp\Tools\BaseDeleteTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Delete Note')]
#[Description('Delete a note from the CRM (soft delete).')]
final class DeleteNoteTool extends BaseDeleteTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Note;
    }

    protected function actionClass(): string
    {
        return DeleteNote::class;
    }
}
