<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Actions\People\CreatePeople;
use App\Http\Resources\V1\PeopleResource;
use App\Mcp\Tools\BaseCreateTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Create a new person (contact) in the CRM. Use the crm-schema resource to discover available custom fields.')]
#[IsOpenWorld(false)]
final class CreatePeopleTool extends BaseCreateTool
{
    protected function actionClass(): string
    {
        return CreatePeople::class;
    }

    protected function resourceClass(): string
    {
        return PeopleResource::class;
    }

    protected function entityType(): string
    {
        return 'people';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The person\'s full name.')->required(),
            'company_id' => $schema->string()->description('The company this person belongs to.'),
        ];
    }

    protected function entityRules(User $user): array
    {
        $teamId = $user->currentTeam->getKey();

        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['sometimes', 'nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
        ];
    }
}
