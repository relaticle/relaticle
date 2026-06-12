<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\People;

use App\Actions\People\CreatePeople;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Relaticle\Chat\Tools\BaseWriteCreateTool;

final class CreatePersonTool extends BaseWriteCreateTool
{
    public function description(): string
    {
        return 'Propose creating a new person/contact. Returns a proposal for user approval.';
    }

    protected function actionClass(): string
    {
        return CreatePeople::class;
    }

    protected function entityType(): string
    {
        return 'people';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The person name.')->required(),
            'company_id' => $schema->string()->description('The company ID to associate with.'),
        ];
    }

    protected function extractRecordData(array $record): array
    {
        return array_filter([
            'name' => (string) ($record['name'] ?? ''),
            'company_id' => $record['company_id'] ?? null,
        ], fn (mixed $v): bool => $v !== null && $v !== '');
    }

    protected function buildRecordDisplay(array $record): array
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $name = (string) ($record['name'] ?? '');
        $fields = [['label' => 'Name', 'value' => $name]];

        $companyId = $record['company_id'] ?? null;
        $companyId = is_string($companyId) && $companyId !== '' ? $companyId : null;
        $companyName = $this->nameForId($companyId, Company::class, 'name', $team);
        if ($companyName !== '') {
            $fields[] = ['label' => 'Company', 'value' => $companyName];
        }

        return [
            'title' => 'Create Person',
            'summary' => "Create person \"{$name}\"",
            'fields' => $fields,
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function nameForId(?string $id, string $modelClass, string $nameAttribute, ?Team $team): string
    {
        if ($id === null) {
            return '';
        }

        $query = $modelClass::query()->whereKey($id);
        if ($team instanceof Team) {
            $query->where('team_id', $team->getKey());
        }

        return (string) ($query->value($nameAttribute) ?? '');
    }
}
