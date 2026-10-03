<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Actions\Note\ListNotes;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\NoteResource;
use App\Mcp\Tools\BaseListTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('List Notes')]
#[Description('List notes in the CRM with optional filters and pagination.')]
final class ListNotesTool extends BaseListTool
{
    protected function actionClass(): string
    {
        return ListNotes::class;
    }

    protected function entity(): CrmEntity
    {
        return CrmEntity::Note;
    }

    protected function resourceClass(): string
    {
        return NoteResource::class;
    }
}
