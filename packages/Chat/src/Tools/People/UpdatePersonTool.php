<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\People;

use App\Actions\People\UpdatePeople;
use App\Models\Company;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
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

    protected function ownedForeignKeys(): array
    {
        return [
            'company_id' => Company::class,
        ];
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The new person name.'),
            'company_id' => $schema->string()->description('The new company ULID. Pass null to unlink the person from their company.'),
        ];
    }

    protected function extractActionData(Request $request): array
    {
        $data = array_filter(['name' => $request['name'] ?? null], fn (mixed $v): bool => $v !== null);

        if (array_key_exists('company_id', $request->all())) {
            $data['company_id'] = $this->stringOrNull($request, 'company_id');
        }

        return $data;
    }

    protected function buildDisplayData(Request $request, Model $model): array
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $fields = [];

        if (($request['name'] ?? null) !== null) {
            $fields[] = [
                'label' => 'Name',
                'old' => $model->getAttribute('name'),
                'new' => $request['name'],
            ];
        }

        if (array_key_exists('company_id', $request->all())) {
            $newCompanyId = $this->stringOrNull($request, 'company_id');
            $fields[] = [
                'label' => 'Company',
                'old' => $this->nameForId($model->getAttribute('company_id'), Company::class, 'name', $team),
                'new' => $newCompanyId === null ? __('(none)') : $this->nameForId($newCompanyId, Company::class, 'name', $team),
            ];
        }

        return [
            'title' => 'Update Person',
            'summary' => "Update person \"{$model->getAttribute('name')}\"",
            'fields' => $fields,
        ];
    }

    private function stringOrNull(Request $request, string $key): ?string
    {
        $value = $request[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function nameForId(?string $id, string $modelClass, string $nameAttribute, ?Team $team): string
    {
        if ($id === null || $id === '') {
            return '';
        }

        $query = $modelClass::query()->whereKey($id);
        if ($team instanceof Team) {
            $query->where('team_id', $team->getKey());
        }

        return (string) ($query->value($nameAttribute) ?? '');
    }
}
