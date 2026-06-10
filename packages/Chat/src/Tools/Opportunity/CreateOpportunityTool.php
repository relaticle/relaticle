<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Opportunity;

use App\Actions\Opportunity\CreateOpportunity;
use App\Models\Company;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseWriteCreateTool;

final class CreateOpportunityTool extends BaseWriteCreateTool
{
    public function description(): string
    {
        return 'Propose creating a new opportunity/deal. Optionally link to a company and primary contact.';
    }

    protected function actionClass(): string
    {
        return CreateOpportunity::class;
    }

    protected function entityType(): string
    {
        return 'opportunity';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The opportunity name.')->required(),
            'company_id' => $schema->string()->description('Linked company ULID.'),
            'contact_id' => $schema->string()->description('Linked primary contact (people) ULID.'),
        ];
    }

    protected function extractActionData(Request $request): array
    {
        return array_filter([
            'name' => (string) $request->string('name'),
            'company_id' => $request['company_id'] ?? null,
            'contact_id' => $request['contact_id'] ?? null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    protected function buildDisplayData(Request $request): array
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $name = (string) $request->string('name');
        $fields = [['label' => 'Name', 'value' => $name]];

        $companyName = $this->nameForId($this->stringOrNull($request, 'company_id'), Company::class, 'name', $team);
        if ($companyName !== '') {
            $fields[] = ['label' => 'Company', 'value' => $companyName];
        }

        $contactName = $this->nameForId($this->stringOrNull($request, 'contact_id'), People::class, 'name', $team);
        if ($contactName !== '') {
            $fields[] = ['label' => 'Contact', 'value' => $contactName];
        }

        return [
            'title' => 'Create Opportunity',
            'summary' => "Create opportunity \"{$name}\"",
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
