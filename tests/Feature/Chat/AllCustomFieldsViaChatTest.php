<?php

declare(strict_types=1);

use App\Actions\Company\UpdateCompany;
use App\Actions\Note\UpdateNote;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\People\UpdatePeople;
use App\Actions\Task\UpdateTask;
use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\Company\UpdateCompanyTool;
use Relaticle\Chat\Tools\Note\UpdateNoteTool;
use Relaticle\Chat\Tools\Opportunity\UpdateOpportunityTool;
use Relaticle\Chat\Tools\People\UpdatePersonTool;
use Relaticle\Chat\Tools\Task\UpdateTaskTool;

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-3333-7000-8000-000000000123',
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('updates the task description via custom_fields and persists as text_value', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['description' => 'Long body text']);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(rawValueForCustomFieldsTest($task, 'description', 'text_value'))->toContain('Long body text');
});

it('updates the task status by option label and persists the option id', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['status' => 'In progress']);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(optionLabelForCustomFieldsTest($task, 'status'))->toBe('In progress');
});

it('updates the task priority by option label and persists the option id', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['priority' => 'High']);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(optionLabelForCustomFieldsTest($task, 'priority'))->toBe('High');
});

it('updates the task due_date via ISO 8601 and persists as datetime_value', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['due_date' => '2026-06-15T09:30:00Z']);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    $stored = rawValueForCustomFieldsTest($task, 'due_date', 'datetime_value');
    expect($stored)->not->toBeNull()
        ->and((string) $stored)->toContain('2026-06-15');
});

it('updates company domains via custom_fields and persists as json_value', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme']);

    runUpdateToolForCustomFieldsTest(UpdateCompanyTool::class, $company, ['domains' => ['acme.com', 'acme.io']]);
    resolve(UpdateCompany::class)->execute($this->user, $company, latestPendingForCustomFieldsTest()->action_data);

    $stored = jsonValueForCustomFieldsTest($company, 'domains');
    expect($stored)->toBe(['acme.com', 'acme.io']);
});

it('updates the note body via custom_fields and persists as text_value', function (): void {
    $note = Note::factory()->for($this->team)->create(['title' => 'N']);

    runUpdateToolForCustomFieldsTest(UpdateNoteTool::class, $note, ['body' => 'Body text']);
    resolve(UpdateNote::class)->execute($this->user, $note, latestPendingForCustomFieldsTest()->action_data);

    expect(rawValueForCustomFieldsTest($note, 'body', 'text_value'))->toContain('Body text');
});

it('updates the opportunity stage by option label and persists the option id', function (): void {
    $opportunity = Opportunity::factory()->for($this->team)->create(['name' => 'O']);

    $stageLabel = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->firstOrFail()
        ->options
        ->first()
        ->name;

    runUpdateToolForCustomFieldsTest(UpdateOpportunityTool::class, $opportunity, ['stage' => $stageLabel]);
    resolve(UpdateOpportunity::class)->execute($this->user, $opportunity, latestPendingForCustomFieldsTest()->action_data);

    expect(optionLabelForCustomFieldsTest($opportunity, 'stage'))->toBe($stageLabel);
});

it('updates person emails via custom_fields and persists as json_value', function (): void {
    $person = People::factory()->for($this->team)->create(['name' => 'P']);

    runUpdateToolForCustomFieldsTest(UpdatePersonTool::class, $person, ['emails' => ['p@example.com']]);
    resolve(UpdatePeople::class)->execute($this->user, $person, latestPendingForCustomFieldsTest()->action_data);

    expect(jsonValueForCustomFieldsTest($person, 'emails'))->toBe(['p@example.com']);
});

it('returns a tool error for an unknown custom field code', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'T']);

    $tool = resolve(UpdateTaskTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000123');

    $response = $tool->handle(new Request([
        'id' => (string) $task->id,
        'custom_fields' => ['this_does_not_exist' => 'whatever'],
    ]));

    expect($response)->toContain('this_does_not_exist');
});

it('returns a tool error for an unknown option label on a choice field', function (): void {
    $task = Task::factory()->for($this->team)->create(['title' => 'T']);

    $tool = resolve(UpdateTaskTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000123');

    $response = $tool->handle(new Request([
        'id' => (string) $task->id,
        'custom_fields' => ['status' => 'Bananas'],
    ]));

    expect($response)->toContain('Bananas');
});

/**
 * @param  class-string  $toolClass
 * @param  array<string, mixed>  $customFields
 */
function runUpdateToolForCustomFieldsTest(string $toolClass, Model $model, array $customFields): void
{
    $tool = resolve($toolClass);
    $tool->setConversationId('019df800-3333-7000-8000-000000000123');

    $tool->handle(new Request([
        'id' => (string) $model->getKey(),
        'custom_fields' => $customFields,
    ]));
}

function latestPendingForCustomFieldsTest(): PendingAction
{
    /** @var PendingAction */
    return PendingAction::query()->latest()->firstOrFail();
}

function rawValueForCustomFieldsTest(Model $model, string $code, string $column): mixed
{
    $field = CustomField::query()
        ->where('tenant_id', $model->getAttribute('team_id'))
        ->where('entity_type', morphAliasForCustomFieldsTest($model))
        ->where('code', $code)
        ->firstOrFail();

    return DB::table('custom_field_values')
        ->where('entity_id', $model->getKey())
        ->where('custom_field_id', $field->getKey())
        ->value($column);
}

/**
 * @return array<int, string>|null
 */
function jsonValueForCustomFieldsTest(Model $model, string $code): ?array
{
    $raw = rawValueForCustomFieldsTest($model, $code, 'json_value');

    if ($raw === null) {
        return null;
    }

    if (is_array($raw)) {
        /** @var array<int, string> */
        return array_values($raw);
    }

    if (! is_string($raw)) {
        return null;
    }

    $decoded = json_decode($raw, true);

    return is_array($decoded) ? array_values(array_map(strval(...), $decoded)) : null;
}

function optionLabelForCustomFieldsTest(Model $model, string $code): ?string
{
    $field = CustomField::query()
        ->where('tenant_id', $model->getAttribute('team_id'))
        ->where('entity_type', morphAliasForCustomFieldsTest($model))
        ->where('code', $code)
        ->with('options')
        ->firstOrFail();

    $optionId = DB::table('custom_field_values')
        ->where('entity_id', $model->getKey())
        ->where('custom_field_id', $field->getKey())
        ->value('string_value');

    if (! is_string($optionId)) {
        return null;
    }

    $option = $field->options->firstWhere('id', $optionId);

    return $option?->name;
}

function morphAliasForCustomFieldsTest(Model $model): string
{
    return match ($model::class) {
        Task::class => 'task',
        Company::class => 'company',
        Note::class => 'note',
        Opportunity::class => 'opportunity',
        People::class => 'people',
        default => throw new RuntimeException('unsupported model'),
    };
}
