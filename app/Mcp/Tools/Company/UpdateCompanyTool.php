<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\UpdateCompany;
use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Update an existing company in the CRM. Use the crm-schema resource to discover available custom fields.')]
#[IsIdempotent]
final class UpdateCompanyTool extends Tool
{
    use ValidatesCustomFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The company ID to update.')->required(),
            'name' => $schema->string()->description('The company name.'),
            'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. Read the crm-schema resource to see available fields and their types.'),
        ];
    }

    public function handle(Request $request, UpdateCompany $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $rules = array_merge(
            [
                'id' => ['required', 'string'],
                'name' => ['sometimes', 'string', 'max:255'],
                'custom_fields' => ['sometimes', 'array'],
            ],
            $this->customFieldValidationRules($user, 'company', $request->get('custom_fields'), isUpdate: true),
        );

        $validated = $request->validate($rules);

        /** @var Company $company */
        $company = Company::query()->findOrFail($validated['id']);
        Gate::authorize('update', $company);
        unset($validated['id']);

        $company = $action->execute($user, $company, $validated);

        return Response::text(
            new CompanyResource($company->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
