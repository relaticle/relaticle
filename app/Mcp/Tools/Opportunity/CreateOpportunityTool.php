<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Actions\Opportunity\CreateOpportunity;
use App\Enums\CreationSource;
use App\Http\Resources\V1\OpportunityResource;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new opportunity (deal) in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class CreateOpportunityTool extends Tool
{
    use ValidatesCustomFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The opportunity name.')->required(),
            'company_id' => $schema->string()->description('The associated company ID.'),
            'contact_id' => $schema->string()->description('The associated contact (person) ID.'),
            'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. Read the crm-schema resource to see available fields and their types.'),
        ];
    }

    public function handle(Request $request, CreateOpportunity $action): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $teamId = $user->currentTeam->getKey();

        $rules = array_merge(
            [
                'name' => ['required', 'string', 'max:255'],
                'company_id' => ['sometimes', 'nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
                'contact_id' => ['sometimes', 'nullable', 'string', Rule::exists('people', 'id')->where('team_id', $teamId)],
                'custom_fields' => ['sometimes', 'array'],
            ],
            $this->customFieldValidationRules($user, 'opportunity', $request->get('custom_fields')),
        );

        $validated = $request->validate($rules);

        $opportunity = $action->execute($user, $validated, CreationSource::MCP);

        return Response::text(
            new OpportunityResource($opportunity->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
