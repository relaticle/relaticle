<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\CreateCompany;
use App\Enums\CreationSource;
use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new company in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class CreateCompanyTool extends Tool
{
    use ValidatesCustomFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The company name.')->required(),
            'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. Read the crm-schema resource to see available fields and their types.'),
        ];
    }

    public function handle(Request $request, CreateCompany $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $rules = array_merge(
            [
                'name' => ['required', 'string', 'max:255'],
                'custom_fields' => ['sometimes', 'array'],
            ],
            $this->customFieldValidationRules($user, 'company', $request->get('custom_fields')),
        );

        $validated = $request->validate($rules);

        $company = $action->execute($user, $validated, CreationSource::MCP);

        return Response::text(
            new CompanyResource($company->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
