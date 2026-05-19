<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Company;

use App\Actions\Company\UpdateCompany;
use App\Models\Company;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseWriteUpdateTool;

final class UpdateCompanyTool extends BaseWriteUpdateTool
{
    public function description(): string
    {
        return 'Propose updating an existing company. Returns a proposal for user approval.';
    }

    protected function modelClass(): string
    {
        return Company::class;
    }

    protected function actionClass(): string
    {
        return UpdateCompany::class;
    }

    protected function entityType(): string
    {
        return 'company';
    }

    protected function entityLabel(): string
    {
        return 'Company';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return ['name' => $schema->string()->description('The new company name.')];
    }

    protected function extractActionData(Request $request): array
    {
        return array_filter(['name' => $request['name'] ?? null], fn (mixed $v): bool => $v !== null);
    }

    protected function buildDisplayData(Request $request, Model $model): array
    {
        $fields = [];
        if (($request['name'] ?? null) !== null) {
            $fields[] = ['label' => 'Name', 'old' => $model->getAttribute('name'), 'new' => $request['name']];
        }

        return [
            'title' => 'Update Company',
            'summary' => "Update company \"{$model->getAttribute('name')}\"",
            'fields' => $fields,
        ];
    }
}
