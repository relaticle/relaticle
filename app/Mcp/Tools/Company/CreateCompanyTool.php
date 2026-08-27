<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\CreateCompany;
use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\BaseCreateTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Create Company')]
#[Description('Create a new company in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class CreateCompanyTool extends BaseCreateTool
{
    protected function actionClass(): string
    {
        return CreateCompany::class;
    }

    protected function resourceClass(): string
    {
        return CompanyResource::class;
    }

    protected function entityType(): string
    {
        return 'company';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The company name.')->required(),
            'account_owner_id' => $schema->string()->description('Team member ID responsible for this company. Use whoami to discover valid IDs.'),
        ];
    }

    protected function entityRules(User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'account_owner_id' => ['sometimes', 'nullable', 'string', Rule::in($user->currentTeam->allUsers()->pluck('id')->all())],
        ];
    }
}
