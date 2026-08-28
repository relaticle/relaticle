<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\UpdateCompany;
use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\BaseUpdateTool;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('Update Company')]
#[Description('Update an existing company in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class UpdateCompanyTool extends BaseUpdateTool
{
    protected function modelClass(): string
    {
        return Company::class;
    }

    protected function actionClass(): string
    {
        return UpdateCompany::class;
    }

    protected function resourceClass(): string
    {
        return CompanyResource::class;
    }

    protected function entityType(): string
    {
        return 'company';
    }

    protected function entityLabel(): string
    {
        return 'company';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The company name.'),
            'account_owner_id' => $schema->string()->nullable()->description('Team member ID responsible for this company. Pass null to clear it. Use whoami to discover valid IDs.'),
        ];
    }

    protected function entityRules(User $user): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'account_owner_id' => ['sometimes', 'nullable', 'string', Rule::in($user->currentTeam->allUsers()->pluck('id')->all())],
        ];
    }
}
