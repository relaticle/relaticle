<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Actions\Opportunity\UpdateOpportunity;
use App\Http\Resources\V1\OpportunityResource;
use App\Mcp\Tools\BaseUpdateTool;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Update an existing opportunity (deal) in the CRM. Use the crm-schema resource to discover available custom fields.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
final class UpdateOpportunityTool extends BaseUpdateTool
{
    protected function modelClass(): string
    {
        return Opportunity::class;
    }

    protected function actionClass(): string
    {
        return UpdateOpportunity::class;
    }

    protected function resourceClass(): string
    {
        return OpportunityResource::class;
    }

    protected function entityType(): string
    {
        return 'opportunity';
    }

    protected function entityLabel(): string
    {
        return 'opportunity';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The opportunity name.'),
            'company_id' => $schema->string()->description('The associated company ID.'),
            'contact_id' => $schema->string()->description('The associated contact (person) ID.'),
        ];
    }

    protected function entityRules(User $user): array
    {
        $teamId = $user->currentTeam->getKey();

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'company_id' => ['sometimes', 'nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
            'contact_id' => ['sometimes', 'nullable', 'string', Rule::exists('people', 'id')->where('team_id', $teamId)],
        ];
    }
}
