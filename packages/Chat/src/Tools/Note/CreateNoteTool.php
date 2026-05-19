<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Note;

use App\Actions\Note\CreateNote;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseWriteCreateTool;

final class CreateNoteTool extends BaseWriteCreateTool
{
    public function description(): string
    {
        return 'Propose creating a new note. Optionally link to people, companies, and opportunities.';
    }

    protected function actionClass(): string
    {
        return CreateNote::class;
    }

    protected function entityType(): string
    {
        return 'note';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The note title.')->required(),
            'people_ids' => $schema->array()->description('People (contact) ULIDs to link.'),
            'company_ids' => $schema->array()->description('Company ULIDs to link.'),
            'opportunity_ids' => $schema->array()->description('Opportunity ULIDs to link.'),
        ];
    }

    protected function extractActionData(Request $request): array
    {
        return array_filter([
            'title' => (string) $request->string('title'),
            'people_ids' => $this->idArray($request, 'people_ids'),
            'company_ids' => $this->idArray($request, 'company_ids'),
            'opportunity_ids' => $this->idArray($request, 'opportunity_ids'),
        ], static fn (mixed $v): bool => ! in_array($v, [null, '', []], true));
    }

    protected function buildDisplayData(Request $request): array
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $title = (string) $request->string('title');
        $fields = [['label' => 'Title', 'value' => $title]];

        $peopleNames = $this->namesForIds($this->idArray($request, 'people_ids'), People::class, 'name', $team);
        if ($peopleNames !== '') {
            $fields[] = ['label' => 'Linked people', 'value' => $peopleNames];
        }

        $companyNames = $this->namesForIds($this->idArray($request, 'company_ids'), Company::class, 'name', $team);
        if ($companyNames !== '') {
            $fields[] = ['label' => 'Linked companies', 'value' => $companyNames];
        }

        $opportunityNames = $this->namesForIds($this->idArray($request, 'opportunity_ids'), Opportunity::class, 'name', $team);
        if ($opportunityNames !== '') {
            $fields[] = ['label' => 'Linked opportunities', 'value' => $opportunityNames];
        }

        return [
            'title' => 'Create Note',
            'summary' => "Create note \"{$title}\"",
            'fields' => $fields,
        ];
    }

    /**
     * @return list<string>|null
     */
    private function idArray(Request $request, string $key): ?array
    {
        $value = $request[$key] ?? null;
        if (! is_array($value)) {
            return null;
        }

        $clean = [];
        foreach ($value as $id) {
            if (is_string($id) && $id !== '') {
                $clean[] = $id;
            }
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * @param  list<string>|null  $ids
     * @param  class-string<Model>  $modelClass
     */
    private function namesForIds(?array $ids, string $modelClass, string $nameAttribute, ?Team $team): string
    {
        if ($ids === null || $ids === []) {
            return '';
        }

        $instance = new $modelClass;
        $query = $modelClass::query()->whereIn($instance->getKeyName(), $ids);
        if ($team instanceof Team) {
            $query->where('team_id', $team->getKey());
        }

        return $query->pluck($nameAttribute)->implode(', ');
    }
}
