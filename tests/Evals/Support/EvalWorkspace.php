<?php

declare(strict_types=1);

namespace Tests\Evals\Support;

use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\SetCustomFieldOptions;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Relaticle\Chat\Actions\CreateConversation;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final readonly class EvalWorkspace
{
    private const int COMPANIES = 20;

    private const int PEOPLE = 40;

    private const int OPPORTUNITIES = 15;

    private const int TASKS = 25;

    private const int NOTES = 10;

    private const array INDUSTRIES = ['Software', 'Healthcare', 'Manufacturing', 'Retail'];

    private const array SENTENCES = [
        'Confirm the scope with the account team before the next call.',
        'Collect the open questions from the last meeting and group them by owner.',
        'Share the revised timeline with every stakeholder who attended.',
        'Check the contract terms against what was promised during the demo.',
        'Log the outcome in the record so the next person has the full context.',
        'Flag anything that blocks the renewal for review at the weekly sync.',
        'Prepare a short summary of usage over the last quarter for the customer.',
        'Ask finance whether the discount request needs a second approval.',
    ];

    public function __construct(
        public User $owner,
        public User $member,
        public Workspace $workspace,
    ) {}

    public static function seed(): self
    {
        $owner = User::factory()->withPersonalWorkspace()->create(['name' => 'Olive Owner']);
        $workspace = $owner->currentWorkspace;
        $member = User::factory()->create(['name' => 'Mira Member']);
        $workspace->users()->attach($member, ['role' => 'member']);
        $member->switchWorkspace($workspace);

        $fixture = new self($owner, $member, $workspace);

        ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->default()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
        ]));

        resolve(CurrentWorkspace::class)->within($workspace, function () use ($fixture): void {
            $fixture->createCompanyFields();
            $fixture->addWaitingStatus();
            $companies = $fixture->createCompanies();
            $fixture->createPeople($companies);
            $fixture->createOpportunities($companies);
            $fixture->createTasks();
            $fixture->createNotes();
        });

        return $fixture;
    }

    public function conversation(?string $purpose = null): AgentConversation
    {
        if ($purpose === AgentConversation::PURPOSE_SETUP) {
            return $this->setupConversation();
        }

        return resolve(CreateConversation::class)->execute($this->owner, $this->workspace, 'Eval conversation');
    }

    public function recordId(string $entity, string $name): string
    {
        [$model, $column] = match ($entity) {
            'company' => [Company::class, 'name'],
            'people' => [People::class, 'name'],
            'opportunity' => [Opportunity::class, 'name'],
            'task' => [Task::class, 'title'],
            'note' => [Note::class, 'title'],
            default => throw new InvalidArgumentException("Unknown entity \"{$entity}\"."),
        };

        return (string) $model::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->where('workspace_id', $this->workspace->getKey())
            ->where($column, $name)
            ->orderBy('id')
            ->firstOrFail()
            ->getKey();
    }

    public function field(string $entity, string $code): CustomField
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->workspace->getKey())
            ->where('entity_type', $entity)
            ->where('code', $code)
            ->sole();
    }

    private function setupConversation(): AgentConversation
    {
        $existing = AgentConversation::query()
            ->where('workspace_id', $this->workspace->getKey())
            ->where('purpose', AgentConversation::PURPOSE_SETUP)
            ->first();

        if ($existing instanceof AgentConversation) {
            return $existing;
        }

        $conversation = resolve(CreateConversation::class)->execute($this->owner, $this->workspace, 'Eval conversation');
        $conversation->forceFill(['purpose' => AgentConversation::PURPOSE_SETUP])->save();

        return $conversation;
    }

    private function createCompanyFields(): void
    {
        $create = resolve(CreateCustomField::class);

        $create->execute($this->owner, [
            'entity_type' => 'company',
            'name' => 'Industry',
            'code' => 'industry',
            'type' => 'select',
            'options' => array_map(fn (string $name): array => ['name' => $name], self::INDUSTRIES),
        ]);
        $create->execute($this->owner, ['entity_type' => 'company', 'name' => 'Website', 'code' => 'website', 'type' => 'link']);
        $create->execute($this->owner, ['entity_type' => 'company', 'name' => 'Account Type', 'code' => 'account_type', 'type' => 'text']);
    }

    private function addWaitingStatus(): void
    {
        $status = $this->field('task', 'status')->load('options');

        $options = $status->options
            ->map(fn (Model $option): array => ['id' => (string) $option->getKey(), 'was' => (string) $option->name, 'name' => (string) $option->name])
            ->all();

        resolve(SetCustomFieldOptions::class)->execute($this->owner, $status, [
            'options' => [...$options, ['name' => 'Waiting on others']],
            'removed' => [],
        ]);
    }

    /**
     * @return list<Company>
     */
    private function createCompanies(): array
    {
        $industry = $this->field('company', 'industry')->load('options');
        $companies = [];

        foreach (range(1, self::COMPANIES) as $index) {
            $name = $index >= self::COMPANIES - 1 ? 'Duplicate Co' : sprintf('Company %02d', $index);

            $company = Company::factory()->for($this->workspace)->create([
                'name' => $name,
                'account_owner_id' => $this->owner->getKey(),
            ]);

            $company->saveCustomFieldValue($industry, $industry->options[($index - 1) % count(self::INDUSTRIES)]->getKey());
            $company->saveCustomFieldValue($this->field('company', 'website'), sprintf('https://company-%02d.example.com', $index));
            $company->saveCustomFieldValue($this->field('company', 'account_type'), $index % 2 === 0 ? 'Customer' : 'Prospect');

            $companies[] = $company;
        }

        return $companies;
    }

    /**
     * @param  list<Company>  $companies
     */
    private function createPeople(array $companies): void
    {
        foreach (range(1, self::PEOPLE) as $index) {
            People::factory()->for($this->workspace)->create([
                'name' => sprintf('Person %02d', $index),
                'company_id' => $companies[($index - 1) % self::COMPANIES]->getKey(),
            ]);
        }
    }

    /**
     * @param  list<Company>  $companies
     */
    private function createOpportunities(array $companies): void
    {
        $stage = $this->field('opportunity', 'stage')->load('options');
        $amount = $this->field('opportunity', 'amount');

        foreach (range(1, self::OPPORTUNITIES) as $index) {
            $opportunity = Opportunity::factory()->for($this->workspace)->create([
                'name' => sprintf('Opportunity %02d', $index),
                'company_id' => $companies[($index - 1) % self::COMPANIES]->getKey(),
            ]);

            $opportunity->saveCustomFieldValue($stage, $stage->options[($index - 1) % $stage->options->count()]->getKey());
            $opportunity->saveCustomFieldValue($amount, 1000 * $index);
        }
    }

    private function createTasks(): void
    {
        $status = $this->field('task', 'status')->load('options');
        $description = $this->field('task', 'description');

        foreach (range(1, self::TASKS) as $index) {
            $task = Task::factory()->for($this->workspace)->create(['title' => sprintf('Task %02d', $index)]);

            $statusName = match (true) {
                $index <= 13 => 'To do',
                $index <= 19 => 'In progress',
                default => 'Done',
            };

            $task->saveCustomFieldValue($status, $status->options->firstWhere('name', $statusName)->getKey());
            $task->saveCustomFieldValue($description, self::descriptionFor($index));
        }
    }

    private function createNotes(): void
    {
        foreach (range(1, self::NOTES) as $index) {
            Note::factory()->for($this->workspace)->create(['title' => sprintf('Note %02d', $index)]);
        }
    }

    private static function descriptionFor(int $index): string
    {
        $count = count(self::SENTENCES);
        $paragraphs = '';

        foreach (range(0, 1 + $index % 6) as $paragraph) {
            $first = self::SENTENCES[($index + $paragraph) % $count];
            $second = self::SENTENCES[($index + $paragraph + 3) % $count];
            $paragraphs .= "<p>{$first} {$second}</p>";
        }

        $items = '<li>'.self::SENTENCES[$index % $count].'</li><li>'.self::SENTENCES[($index + 5) % $count].'</li>';

        return "{$paragraphs}<ul>{$items}</ul>";
    }
}
