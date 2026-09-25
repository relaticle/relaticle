<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Actions\Note\ListNotes;
use App\Http\Resources\V1\NoteResource;
use App\Mcp\Tools\BaseListTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('List Notes')]
#[Description('List notes in the CRM with optional search and pagination.')]
final class ListNotesTool extends BaseListTool
{
    protected function actionClass(): string
    {
        return ListNotes::class;
    }

    protected function resourceClass(): string
    {
        return NoteResource::class;
    }

    protected function searchFilterName(): string
    {
        return 'title';
    }

    protected function additionalSchema(JsonSchema $schema): array
    {
        return [
            'notable_type' => $schema->string()->description('Filter by related entity type: company, people, or opportunity.'),
            'notable_id' => $schema->string()->description('Filter by related entity ID (use with notable_type for best results).'),
        ];
    }

    protected function additionalFilters(Request $request): array
    {
        return [
            'notable_type' => $request->get('notable_type'),
            'notable_id' => $request->get('notable_id'),
        ];
    }

    protected function additionalValidationRules(User $user): array
    {
        return [
            'notable_type' => ['sometimes', 'string', Rule::in(['company', 'people', 'opportunity'])],
            'notable_id' => ['sometimes', 'string', 'ulid'],
        ];
    }
}
