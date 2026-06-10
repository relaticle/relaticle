<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\People;

use App\Actions\People\UpdatePeople;
use App\Models\People;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseWriteUpdateTool;

final class UpdatePersonTool extends BaseWriteUpdateTool
{
    public function description(): string
    {
        return 'Propose updating an existing person/contact. Returns a proposal for user approval.';
    }

    protected function modelClass(): string
    {
        return People::class;
    }

    protected function actionClass(): string
    {
        return UpdatePeople::class;
    }

    protected function entityType(): string
    {
        return 'people';
    }

    protected function entityLabel(): string
    {
        return 'Person';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The new person name.'),
            'company_id' => $schema->string()->description('The new company ID.'),
        ];
    }

    protected function extractActionData(Request $request): array
    {
        return array_filter([
            'name' => $request['name'] ?? null,
            'company_id' => $request['company_id'] ?? null,
        ], fn (mixed $v): bool => $v !== null);
    }

    protected function buildDisplayData(Request $request, Model $model): array
    {
        $fields = [];
        if (($request['name'] ?? null) !== null) {
            $fields[] = ['label' => 'Name', 'old' => $model->getAttribute('name'), 'new' => $request['name']];
        }

        return [
            'title' => 'Update Person',
            'summary' => "Update person \"{$model->getAttribute('name')}\"",
            'fields' => $fields,
        ];
    }
}
