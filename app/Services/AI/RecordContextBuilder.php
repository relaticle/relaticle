<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\NoteField;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\PeopleField;
use App\Enums\CustomFields\TaskField;
use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RecordContextBuilder
{
    private const int RELATIONSHIP_LIMIT = 10;

    /**
     * Build context data for a record to be used in AI summary generation.
     *
     * @return array<string, mixed>
     */
    public function buildContext(Model $record): array
    {
        return match (true) {
            $record instanceof Company => $this->buildCompanyContext($record),
            $record instanceof People => $this->buildPeopleContext($record),
            $record instanceof Opportunity => $this->buildOpportunityContext($record),
            default => throw new InvalidArgumentException('Unsupported record type: '.$record::class),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCompanyContext(Company $company): array
    {
        $company->loadCount(['notes', 'tasks', 'opportunities', 'people']);

        $company->load([
            'accountOwner',
            'customFieldValues.customField',
            'notes' => $this->recentWithCustomFields('notes'),
            'tasks' => $this->recentWithCustomFields('tasks'),
            'opportunities' => $this->recentWithCustomFields('opportunities'),
        ]);

        return [
            'entity_type' => 'Company',
            'name' => $company->name,
            'basic_info' => $this->getCompanyBasicInfo($company),
            'relationships' => [
                'people_count' => $company->people_count,
                'opportunities_count' => $company->opportunities_count,
            ],
            'opportunities' => $this->formatOpportunities($company->opportunities, $company->opportunities_count),
            'notes' => $this->formatNotes($company->notes, $company->notes_count),
            'tasks' => $this->formatTasks($company->tasks, $company->tasks_count),
            'last_updated' => $company->updated_at?->diffForHumans(),
            'created' => $company->created_at?->diffForHumans(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPeopleContext(People $person): array
    {
        $person->loadCount(['notes', 'tasks']);

        $person->load([
            'company',
            'customFieldValues.customField',
            'notes' => $this->recentWithCustomFields('notes'),
            'tasks' => $this->recentWithCustomFields('tasks'),
        ]);

        return [
            'entity_type' => 'Person',
            'name' => $person->name,
            'basic_info' => $this->getPeopleBasicInfo($person),
            'company' => $person->company?->name,
            'notes' => $this->formatNotes($person->notes, $person->notes_count),
            'tasks' => $this->formatTasks($person->tasks, $person->tasks_count),
            'last_updated' => $person->updated_at?->diffForHumans(),
            'created' => $person->created_at?->diffForHumans(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOpportunityContext(Opportunity $opportunity): array
    {
        $opportunity->loadCount(['notes', 'tasks']);

        $opportunity->load([
            'company',
            'contact',
            'customFieldValues.customField',
            'notes' => $this->recentWithCustomFields('notes'),
            'tasks' => $this->recentWithCustomFields('tasks'),
        ]);

        return [
            'entity_type' => 'Opportunity',
            'name' => $this->getOpportunityName($opportunity),
            'basic_info' => $this->getOpportunityBasicInfo($opportunity),
            'company' => $opportunity->company?->name,
            'contact' => $opportunity->contact?->name,
            'notes' => $this->formatNotes($opportunity->notes, $opportunity->notes_count),
            'tasks' => $this->formatTasks($opportunity->tasks, $opportunity->tasks_count),
            'last_updated' => $opportunity->updated_at?->diffForHumans(),
            'created' => $opportunity->created_at?->diffForHumans(),
        ];
    }

    private function recentWithCustomFields(string $table): Closure
    {
        return fn (Relation $query): Relation => $query
            ->with('customFieldValues.customField')
            ->latest("{$table}.created_at")
            ->limit(self::RELATIONSHIP_LIMIT);
    }

    /**
     * @return array<string, mixed>
     */
    private function getCompanyBasicInfo(Company $company): array
    {
        return collect([
            'domain' => $this->getCustomFieldValue($company, CompanyField::DOMAIN_NAME->value),
            'is_icp' => (bool) $this->getCustomFieldValue($company, CompanyField::ICP->value),
            'account_owner' => $company->accountOwner?->name,
        ])->filter(fn (mixed $value): bool => filled($value))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function getPeopleBasicInfo(People $person): array
    {
        $emails = $this->getCustomFieldValue($person, PeopleField::EMAILS->value);

        return collect([
            'job_title' => $this->getCustomFieldValue($person, PeopleField::JOB_TITLE->value),
            'emails' => is_array($emails) ? implode(', ', $emails) : $emails,
        ])->filter(fn (mixed $value): bool => filled($value))->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function getOpportunityBasicInfo(Opportunity $opportunity): array
    {
        $amount = $this->getCustomFieldValue($opportunity, OpportunityField::AMOUNT->value);
        $closeDate = $this->getCustomFieldValue($opportunity, OpportunityField::CLOSE_DATE->value);

        return collect([
            'stage' => $this->getCustomFieldValue($opportunity, OpportunityField::STAGE->value),
            'amount' => filled($amount) ? '$'.number_format((float) $amount, 2) : null,
            'close_date' => $this->formatDate($closeDate),
        ])->filter(fn (mixed $value): bool => filled($value))->all();
    }

    private function getOpportunityName(Opportunity $opportunity): string
    {
        $stage = $this->getCustomFieldValue($opportunity, OpportunityField::STAGE->value);

        return $opportunity->company?->name.' - '.($stage ?? 'Opportunity');
    }

    /**
     * @param  Collection<int, Note>  $notes
     * @return array<string, mixed>
     */
    private function formatNotes(Collection $notes, int $totalCount): array
    {
        $formatted = $notes->map(fn (Note $note): array => [
            'title' => $note->title,
            'content' => $this->stripHtml((string) $this->getCustomFieldValue($note, NoteField::BODY->value)),
            'created' => $note->created_at?->diffForHumans(),
        ])->values()->all();

        return $this->withPaginationInfo($formatted, $totalCount);
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return array<string, mixed>
     */
    private function formatTasks(Collection $tasks, int $totalCount): array
    {
        $formatted = $tasks->map(fn (Task $task): array => [
            'title' => $task->title,
            'status' => $this->getCustomFieldValue($task, TaskField::STATUS->value),
            'priority' => $this->getCustomFieldValue($task, TaskField::PRIORITY->value),
            'due_date' => $this->formatDate($this->getCustomFieldValue($task, TaskField::DUE_DATE->value)),
        ])->values()->all();

        return $this->withPaginationInfo($formatted, $totalCount);
    }

    /**
     * @param  Collection<int, Opportunity>  $opportunities
     * @return array<string, mixed>
     */
    private function formatOpportunities(Collection $opportunities, int $totalCount): array
    {
        $formatted = $opportunities->map(function (Opportunity $opportunity): array {
            $amount = $this->getCustomFieldValue($opportunity, OpportunityField::AMOUNT->value);

            return [
                'stage' => $this->getCustomFieldValue($opportunity, OpportunityField::STAGE->value),
                'amount' => filled($amount) ? '$'.number_format((float) $amount, 2) : null,
            ];
        })->values()->all();

        return $this->withPaginationInfo($formatted, $totalCount);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function withPaginationInfo(array $items, int $totalCount): array
    {
        $showing = count($items);

        return [
            'items' => $items,
            'showing' => $showing,
            'total' => $totalCount,
            'has_more' => $totalCount > $showing,
        ];
    }

    private function getCustomFieldValue(Model $model, string $code): mixed
    {
        if (! method_exists($model, 'customFieldValues')) {
            return null;
        }

        /** @var Collection<int, \Relaticle\CustomFields\Models\CustomFieldValue> $customFieldValues */
        $customFieldValues = $model->customFieldValues; // @phpstan-ignore property.notFound

        $customFieldValue = $customFieldValues->first(fn (\Relaticle\CustomFields\Models\CustomFieldValue $cfv): bool => $cfv->customField->code === $code);

        if ($customFieldValue === null) {
            return null;
        }

        return $model->getCustomFieldValue($customFieldValue->customField); // @phpstan-ignore method.notFound
    }

    private function formatDate(mixed $date): ?string
    {
        if ($date === null) {
            return null;
        }

        return $date instanceof \DateTimeInterface
            ? $date->format('M j, Y')
            : (string) $date;
    }

    private function stripHtml(string $html): string
    {
        $text = strip_tags($html);

        return Str::limit(trim((string) preg_replace('/\s+/', ' ', $text)), 500);
    }
}
