<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Actions\People\CreatePeople;
use App\Enums\CreationSource;
use App\Http\Resources\V1\PeopleResource;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new person (contact) in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class CreatePeopleTool extends Tool
{
    use ValidatesCustomFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The person\'s full name.')->required(),
            'company_id' => $schema->string()->description('The company this person belongs to.'),
            'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. Read the crm-schema resource to see available fields and their types.'),
        ];
    }

    public function handle(Request $request, CreatePeople $action): Response
    {
        /** @var User $user */
        $user = auth()->user();
        $teamId = $user->currentTeam->getKey();

        $rules = array_merge(
            [
                'name' => ['required', 'string', 'max:255'],
                'company_id' => ['sometimes', 'nullable', 'string', Rule::exists('companies', 'id')->where('team_id', $teamId)],
                'custom_fields' => ['sometimes', 'array'],
            ],
            $this->customFieldValidationRules($user, 'people', $request->get('custom_fields')),
        );

        $validated = $request->validate($rules);

        $person = $action->execute($user, $validated, CreationSource::MCP);

        return Response::text(
            new PeopleResource($person->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
