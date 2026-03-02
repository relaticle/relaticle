<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Actions\Opportunity\UpdateOpportunity;
use App\Http\Resources\V1\OpportunityResource;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Update an existing opportunity (deal) in the CRM. Use the crm-schema resource to discover available custom fields.')]
#[IsIdempotent]
final class UpdateOpportunityTool extends Tool
{
    use ValidatesCustomFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The opportunity ID to update.')->required(),
            'name' => $schema->string()->description('The opportunity name.'),
            'company_id' => $schema->string()->description('The associated company ID.'),
            'contact_id' => $schema->string()->description('The associated contact (person) ID.'),
            'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. Read the crm-schema resource to see available fields and their types.'),
        ];
    }

    public function handle(Request $request, UpdateOpportunity $action): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $teamId = $user->currentTeam->getKey();

        $rules = array_merge(
            [
                'id' => ['required', 'string'],
                'name' => ['sometimes', 'string', 'max:255'],
                'company_id' => ['sometimes', 'nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
                'contact_id' => ['sometimes', 'nullable', 'string', Rule::exists('people', 'id')->where('team_id', $teamId)],
                'custom_fields' => ['sometimes', 'array'],
            ],
            $this->customFieldValidationRules($user, 'opportunity', $request->get('custom_fields'), isUpdate: true),
        );

        $validated = $request->validate($rules);

        /** @var Opportunity $opportunity */
        $opportunity = Opportunity::query()->findOrFail($validated['id']);
        Gate::authorize('update', $opportunity);
        unset($validated['id']);

        $opportunity = $action->execute($user, $opportunity, $validated);

        return Response::text(
            new OpportunityResource($opportunity->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
