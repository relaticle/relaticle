<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\UpdateCompany;
use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\BaseUpdateTool;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[Description('Update an existing company in the CRM. Use the crm-schema resource to discover available custom fields.')]
#[IsIdempotent]
#[IsOpenWorld(false)]
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
        ];
    }

    protected function entityRules(User $user): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
