<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Actions\Note\DetachNoteRelationships;
use App\Http\Resources\V1\NoteResource;
use App\Mcp\Tools\BaseDetachTool;
use App\Models\Note;
use App\Models\User;
use App\Rules\ArrayExistsForTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Detach Note Relationships')]
#[Description('Detach a note from companies, people, or opportunities. Removes specified links.')]
final class DetachNoteFromEntitiesTool extends BaseDetachTool
{
    protected function modelClass(): string
    {
        return Note::class;
    }

    protected function entityLabel(): string
    {
        return 'Note';
    }

    protected function resourceClass(): string
    {
        return NoteResource::class;
    }

    protected function actionClass(): string
    {
        return DetachNoteRelationships::class;
    }

    /** @return array<int, string> */
    protected function relationshipsToLoad(): array
    {
        return ['companies', 'people', 'opportunities'];
    }

    public function relationshipSchema(JsonSchema $schema): array
    {
        return [
            'company_ids' => $schema->array()->description('Company IDs to detach from this note.'),
            'people_ids' => $schema->array()->description('People IDs to detach from this note.'),
            'opportunity_ids' => $schema->array()->description('Opportunity IDs to detach from this note.'),
        ];
    }

    public function relationshipRules(User $user): array
    {
        $teamId = $user->currentTeam->getKey();

        return [
            'company_ids' => ['sometimes', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForTeam('companies', 'company_ids', $teamId)],
            'people_ids' => ['sometimes', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForTeam('people', 'people_ids', $teamId)],
            'opportunity_ids' => ['sometimes', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForTeam('opportunities', 'opportunity_ids', $teamId)],
        ];
    }
}
