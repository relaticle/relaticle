<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Actions\People\UpdatePeople;
use App\Http\Resources\V1\PeopleResource;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\People;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Update an existing person (contact) in the CRM. Use the crm-schema resource to discover available custom fields.')]
#[IsIdempotent]
final class UpdatePeopleTool extends Tool
{
    use ValidatesCustomFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The person ID to update.')->required(),
            'name' => $schema->string()->description('The person\'s full name.'),
            'company_id' => $schema->string()->description('The company this person belongs to.'),
            'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. Read the crm-schema resource to see available fields and their types.'),
        ];
    }

    public function handle(Request $request, UpdatePeople $action): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $teamId = $user->currentTeam->getKey();

        $rules = array_merge(
            [
                'id' => ['required', 'string'],
                'name' => ['sometimes', 'string', 'max:255'],
                'company_id' => ['sometimes', 'nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
                'custom_fields' => ['sometimes', 'array'],
            ],
            $this->customFieldValidationRules($user, 'people', $request->get('custom_fields'), isUpdate: true),
        );

        $validated = $request->validate($rules);

        /** @var People $person */
        $person = People::query()->findOrFail($validated['id']);
        Gate::authorize('update', $person);
        unset($validated['id']);

        $person = $action->execute($user, $person, $validated);

        return Response::text(
            new PeopleResource($person->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
