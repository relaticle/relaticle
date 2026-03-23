<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Actions\People\UpdatePeople;
use App\Http\Resources\V1\PeopleResource;
use App\Mcp\Tools\BaseUpdateTool;
use App\Models\People;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Update an existing person (contact) in the CRM. Use the crm-schema resource to discover available custom fields.')]
#[IsIdempotent]
final class UpdatePeopleTool extends BaseUpdateTool
{
    protected function modelClass(): string
    {
        return People::class;
    }

    protected function actionClass(): string
    {
        return UpdatePeople::class;
    }

    protected function resourceClass(): string
    {
        return PeopleResource::class;
    }

    protected function entityType(): string
    {
        return 'people';
    }

    protected function entityLabel(): string
    {
        return 'person';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The person\'s full name.'),
            'company_id' => $schema->string()->description('The company this person belongs to.'),
        ];
    }

    protected function entityRules(User $user): array
    {
        $teamId = $user->currentTeam->getKey();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'company_id' => ['sometimes', 'nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
        ];
    }
}
