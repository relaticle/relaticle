<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Note;

use App\Actions\Note\ListNotes;
use App\Http\Resources\V1\NoteResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseReadListTool;

final class ListNotesTool extends BaseReadListTool
{
    public function description(): string
    {
        return 'List notes with optional search, pagination, and filtering to the notes attached to a specific company, person, or opportunity.';
    }

    protected function actionClass(): string
    {
        return ListNotes::class;
    }

    protected function resourceClass(): string
    {
        return NoteResource::class;
    }

    /** @return array<string, mixed> */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [
            'notable_type' => $schema->string()->description('Restrict to notes attached to this record type. One of: company, people, opportunity. Always pass together with notable_id.'),
            'notable_id' => $schema->string()->description('Restrict to notes attached to this record ID. Always pass together with notable_type.'),
        ];
    }

    /** @return array<string, mixed> */
    protected function additionalFilters(Request $request): array
    {
        return array_filter([
            'notable_type' => $request['notable_type'] ?? null,
            'notable_id' => $request['notable_id'] ?? null,
        ]);
    }

    protected function searchFilterName(): string
    {
        return 'title';
    }

    protected function citationType(): string
    {
        return 'note';
    }
}
