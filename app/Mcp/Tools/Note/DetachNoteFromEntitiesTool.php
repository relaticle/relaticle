<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Http\Resources\V1\NoteResource;
use App\Mcp\Tools\BaseDetachTool;
use App\Models\Note;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;

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
            'company_ids.*' => ['string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
            'people_ids' => ['sometimes', 'array'],
            'people_ids.*' => ['string', Rule::exists('people', 'id')->where('team_id', $teamId)],
            'opportunity_ids' => ['sometimes', 'array'],
            'opportunity_ids.*' => ['string', Rule::exists('opportunities', 'id')->where('team_id', $teamId)],
        ];
    }

    public function detachRelationships(Model $model, array $data): void
    {
        /** @var Note $model */
        if (isset($data['company_ids'])) {
            $model->companies()->detach($data['company_ids']);
        }

        if (isset($data['people_ids'])) {
            $model->people()->detach($data['people_ids']);
        }

        if (isset($data['opportunity_ids'])) {
            $model->opportunities()->detach($data['opportunity_ids']);
        }
    }
}
