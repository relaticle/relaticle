<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Note;

use App\Actions\Note\CreateNote;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Relaticle\Chat\Tools\BaseWriteCreateTool;
use Relaticle\Chat\Tools\Concerns\NormalizesToolInput;

final class CreateNoteTool extends BaseWriteCreateTool
{
    use NormalizesToolInput;

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

    protected function ownedForeignKeyLists(): array
    {
        return [
            'company_ids' => Company::class,
            'people_ids' => People::class,
            'opportunity_ids' => Opportunity::class,
        ];
    }

    protected function nameAttribute(): string
    {
        return 'title';
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

    protected function extractRecordData(array $record): array
    {
        return array_filter([
            'title' => (string) ($record['title'] ?? ''),
            'people_ids' => $this->idListFromArray($record, 'people_ids'),
            'company_ids' => $this->idListFromArray($record, 'company_ids'),
            'opportunity_ids' => $this->idListFromArray($record, 'opportunity_ids'),
        ], static fn (mixed $v): bool => ! in_array($v, [null, '', []], true));
    }

    protected function buildRecordDisplay(array $record): array
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $title = (string) ($record['title'] ?? '');
        $fields = [['label' => 'Title', 'value' => $title]];

        $peopleNames = $this->recordNames()->names($this->idListFromArray($record, 'people_ids'), People::class, $team);
        if ($peopleNames !== '') {
            $fields[] = ['label' => 'Linked people', 'value' => $peopleNames];
        }

        $companyNames = $this->recordNames()->names($this->idListFromArray($record, 'company_ids'), Company::class, $team);
        if ($companyNames !== '') {
            $fields[] = ['label' => 'Linked companies', 'value' => $companyNames];
        }

        $opportunityNames = $this->recordNames()->names($this->idListFromArray($record, 'opportunity_ids'), Opportunity::class, $team);
        if ($opportunityNames !== '') {
            $fields[] = ['label' => 'Linked opportunities', 'value' => $opportunityNames];
        }

        return [
            'title' => 'Create Note',
            'summary' => "Create note \"{$title}\"",
            'fields' => $fields,
        ];
    }
}
