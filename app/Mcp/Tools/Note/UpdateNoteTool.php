<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Actions\Note\UpdateNote;
use App\Concerns\OperatesOnCrmEntity;
use App\Enums\CrmEntity;
use App\Http\Resources\V1\NoteResource;
use App\Mcp\Tools\BaseUpdateTool;
use App\Models\User;
use App\Rules\ArrayExistsForTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Update Note')]
#[Description('Update an existing note in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class UpdateNoteTool extends BaseUpdateTool
{
    use OperatesOnCrmEntity;

    protected function entity(): CrmEntity
    {
        return CrmEntity::Note;
    }

    protected function actionClass(): string
    {
        return UpdateNote::class;
    }

    protected function resourceClass(): string
    {
        return NoteResource::class;
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The note title.'),
            'company_ids' => $schema->array()->description('Company IDs to link. Omit to leave unchanged, pass [] to remove all.'),
            'people_ids' => $schema->array()->description('People IDs to link. Omit to leave unchanged, pass [] to remove all.'),
            'opportunity_ids' => $schema->array()->description('Opportunity IDs to link. Omit to leave unchanged, pass [] to remove all.'),
        ];
    }

    protected function entityRules(User $user): array
    {
        $teamId = $user->currentTeam->getKey();

        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'company_ids' => ['sometimes', 'array'],
            'company_ids.*' => ['string', new ArrayExistsForTeam('companies', 'company_ids', $teamId)],
            'people_ids' => ['sometimes', 'array'],
            'people_ids.*' => ['string', new ArrayExistsForTeam('people', 'people_ids', $teamId)],
            'opportunity_ids' => ['sometimes', 'array'],
            'opportunity_ids.*' => ['string', new ArrayExistsForTeam('opportunities', 'opportunity_ids', $teamId)],
        ];
    }
}
