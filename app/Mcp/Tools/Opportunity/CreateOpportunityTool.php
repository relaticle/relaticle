<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Actions\Opportunity\CreateOpportunity;
use App\Http\Resources\V1\OpportunityResource;
use App\Mcp\Tools\BaseCreateTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a new opportunity (deal) in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class CreateOpportunityTool extends BaseCreateTool
{
    protected function actionClass(): string
    {
        return CreateOpportunity::class;
    }

    protected function resourceClass(): string
    {
        return OpportunityResource::class;
    }

    protected function entityType(): string
    {
        return 'opportunity';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The opportunity name.')->required(),
            'company_id' => $schema->string()->description('The associated company ID.'),
            'contact_id' => $schema->string()->description('The associated contact (person) ID.'),
        ];
    }

    protected function entityRules(User $user): array
    {
        $teamId = $user->currentTeam->getKey();

        return [
            'name' => ['required', 'string', 'max:255'],
            'company_id' => ['sometimes', 'nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
            'contact_id' => ['sometimes', 'nullable', 'string', Rule::exists('people', 'id')->where('team_id', $teamId)],
        ];
    }
}
