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
use App\Support\CustomFields\CustomFieldInput;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\Tools\CustomFieldsRequestValidator;
use Relaticle\Chat\Tools\Company\UpdateCompanyTool;
use Relaticle\Chat\Tools\Note\UpdateNoteTool;
use Relaticle\Chat\Tools\Opportunity\UpdateOpportunityTool;
use Relaticle\Chat\Tools\People\UpdatePersonTool;
use Relaticle\Chat\Tools\Task\ListTasksTool;
use Relaticle\Chat\Tools\Task\UpdateTaskTool;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\LegacyCompanyDomains;

mutates(CustomFieldInput::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;
    Auth::guard('web')->setUser($this->user);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-3333-7000-8000-000000000123',
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->workspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

it('updates the task description via custom_fields and persists as text_value', function (): void {
    $task = Task::factory()->for($this->workspace)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['description' => 'Long body text']);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(rawValueForCustomFieldsTest($task, 'description', 'text_value'))->toContain('Long body text');
});

it('updates the task status by option label and persists the option id', function (): void {
    $task = Task::factory()->for($this->workspace)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['status' => 'In progress']);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(optionLabelForCustomFieldsTest($task, 'status'))->toBe('In progress');
});

it('updates the task priority by option label and persists the option id', function (): void {
    $task = Task::factory()->for($this->workspace)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['priority' => 'High']);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(optionLabelForCustomFieldsTest($task, 'priority'))->toBe('High');
});

it('updates the task due_date via ISO 8601 and persists as datetime_value', function (): void {
    $task = Task::factory()->for($this->workspace)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['due_date' => '2026-06-15T09:30:00Z']);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    $stored = rawValueForCustomFieldsTest($task, 'due_date', 'datetime_value');
    expect($stored)->not->toBeNull()
        ->and((string) $stored)->toContain('2026-06-15');
});

it('updates company domains via custom_fields and persists as json_value', function (): void {
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme']);

    runUpdateToolForCustomFieldsTest(UpdateCompanyTool::class, $company, ['domains' => ['acme.com', 'acme.io']]);
    resolve(UpdateCompany::class)->execute($this->user, $company, latestPendingForCustomFieldsTest()->action_data);

    $stored = jsonValueForCustomFieldsTest($company, 'domains');
    expect($stored)->toBe(['acme.com', 'acme.io']);
});

it('accepts a company keeping its own legacy domain while another company holds the canonical form', function (): void {
    ['own' => $own] = LegacyCompanyDomains::seed($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());

    runUpdateToolForCustomFieldsTest(UpdateCompanyTool::class, $own, ['domains' => ['https://acme.com', 'fresh.com']]);
    resolve(UpdateCompany::class)->execute($this->user, $own, latestPendingForCustomFieldsTest()->action_data);

    expect(jsonValueForCustomFieldsTest($own, 'domains'))->toBe(['acme.com', 'fresh.com']);
});

it('rejects a domain another company holds in a different spelling', function (): void {
    ['own' => $own] = LegacyCompanyDomains::seed($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());

    $response = runUpdateToolForCustomFieldsTest(UpdateCompanyTool::class, $own, ['domains' => ['https://acme.com', 'www.other.com']]);

    expect($response)->toContain('www.other.com')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('proposes no change for a domain that only differs by spelling', function (string $spelling): void {
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme']);
    $company->saveCustomFields(['domains' => ['acme.com']]);

    $response = json_decode(runUpdateToolForCustomFieldsTest(UpdateCompanyTool::class, $company, ['domains' => [$spelling]]), true);

    expect($response['error'])->toContain('Nothing to update')
        ->and($response['skipped'][0]['reason'])->toContain('Already up to date')
        ->and(PendingAction::query()->count())->toBe(0);
})->with(['https://www.acme.com', 'ACME.com/about']);

it('proposes no change for a list repeating the stored value in another spelling', function (): void {
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme']);
    $company->saveCustomFields(['domains' => ['acme.com']]);

    $response = json_decode(runUpdateToolForCustomFieldsTest(UpdateCompanyTool::class, $company, ['domains' => ['acme.com', 'www.acme.com']]), true);

    expect($response['error'])->toContain('Nothing to update')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('proposes no change for a phone that only differs by formatting', function (): void {
    $person = People::factory()->for($this->workspace)->create(['name' => 'Ana']);
    $person->saveCustomFields(['phone_number' => ['+14155550100']]);

    $response = json_decode(runUpdateToolForCustomFieldsTest(UpdatePersonTool::class, $person, ['phone_number' => ['+1 (415) 555-0100']]), true);

    expect($response['error'])->toContain('Nothing to update')
        ->and(PendingAction::query()->count())->toBe(0);
});

it('updates the note body via custom_fields and persists as text_value', function (): void {
    $note = Note::factory()->for($this->workspace)->create(['title' => 'N']);

    runUpdateToolForCustomFieldsTest(UpdateNoteTool::class, $note, ['body' => 'Body text']);
    resolve(UpdateNote::class)->execute($this->user, $note, latestPendingForCustomFieldsTest()->action_data);

    expect(rawValueForCustomFieldsTest($note, 'body', 'text_value'))->toContain('Body text');
});

it('updates the opportunity stage by option label and persists the option id', function (): void {
    $opportunity = Opportunity::factory()->for($this->workspace)->create(['name' => 'O']);

    $stageLabel = CustomField::query()
        ->where('tenant_id', $this->workspace->getKey())
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
    $person = People::factory()->for($this->workspace)->create(['name' => 'P']);

    runUpdateToolForCustomFieldsTest(UpdatePersonTool::class, $person, ['emails' => ['p@example.com']]);
    resolve(UpdatePeople::class)->execute($this->user, $person, latestPendingForCustomFieldsTest()->action_data);

    expect(jsonValueForCustomFieldsTest($person, 'emails'))->toBe(['p@example.com']);
});

it('returns a tool error for an unknown custom field code', function (): void {
    $task = Task::factory()->for($this->workspace)->create(['title' => 'T']);

    $tool = resolve(UpdateTaskTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000123');

    $response = $tool->handle(new Request(['records' => [[
        'id' => (string) $task->id,
        'custom_fields' => ['this_does_not_exist' => 'whatever'],
    ]]]));

    expect($response)->toContain('this_does_not_exist');
});

it('returns a tool error for an unknown option label on a choice field', function (): void {
    $task = Task::factory()->for($this->workspace)->create(['title' => 'T']);

    $tool = resolve(UpdateTaskTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000123');

    $response = $tool->handle(new Request(['records' => [[
        'id' => (string) $task->id,
        'custom_fields' => ['status' => 'Bananas'],
    ]]]));

    expect($response)->toContain('Bananas');
});

/**
 * @param  class-string  $toolClass
 * @param  array<string, mixed>  $customFields
 */
function runUpdateToolForCustomFieldsTest(string $toolClass, Model $model, array $customFields): string
{
    $tool = resolve($toolClass);
    $tool->setConversationId('019df800-3333-7000-8000-000000000123');

    return $tool->handle(new Request(['records' => [[
        'id' => (string) $model->getKey(),
        'custom_fields' => $customFields,
    ]]]));
}

function latestPendingForCustomFieldsTest(): PendingAction
{
    // Ordered by key, not created_at: two writes inside the same second tie on
    // the timestamp and `latest()` then returns an arbitrary one of them.
    /** @var PendingAction */
    return PendingAction::query()->orderByDesc('id')->firstOrFail();
}

it('clears a single-choice custom field when chat passes null', function (): void {
    $task = Task::factory()->for($this->workspace)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['priority' => 'High']);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(rawValueForCustomFieldsTest($task, 'priority', 'string_value'))->not->toBeNull();

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['priority' => null]);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(rawValueForCustomFieldsTest($task, 'priority', 'string_value'))->toBeNull();
});

it('clears a text custom field when chat passes null', function (): void {
    $task = Task::factory()->for($this->workspace)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['description' => 'Long body text']);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(rawValueForCustomFieldsTest($task, 'description', 'text_value'))->toContain('Long body text');

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['description' => null]);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(rawValueForCustomFieldsTest($task, 'description', 'text_value'))->toBeNull();
});

function rawValueForCustomFieldsTest(Model $model, string $code, string $column): mixed
{
    $field = CustomField::query()
        ->where('tenant_id', $model->getAttribute('workspace_id'))
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
        ->where('tenant_id', $model->getAttribute('workspace_id'))
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

it('accepts an option label in any casing when writing a choice field', function (string $label): void {
    $task = Task::factory()->for($this->workspace)->create(['title' => 'T']);

    runUpdateToolForCustomFieldsTest(UpdateTaskTool::class, $task, ['status' => $label]);
    resolve(UpdateTask::class)->execute($this->user, $task, latestPendingForCustomFieldsTest()->action_data);

    expect(optionLabelForCustomFieldsTest($task, 'status'))->toBe('In progress');
})->with(['In progress', 'in progress', 'IN PROGRESS', 'In Progress']);

it('resolves an option label identically whether filtering or writing', function (string $label, bool $valid): void {
    Task::factory()->for($this->workspace)->create(['title' => 'T']);

    TenantContextService::setTenantId($this->workspace->getKey());

    $readResult = json_decode((new ListTasksTool)->handle(new Request([
        'filter' => ['custom_fields' => ['status' => ['$eq' => $label]]],
    ])), true);

    TenantContextService::setTenantId(null);

    $readAccepted = ! array_key_exists('error', $readResult);

    $writeAccepted = resolve(CustomFieldsRequestValidator::class)
        ->validate($this->user, 'task', ['status' => $label])->error === null;

    expect($readAccepted)->toBe($valid)
        ->and($writeAccepted)->toBe($valid);
})->with([
    'exact casing' => ['In progress', true],
    'lowercased' => ['in progress', true],
    'uppercased' => ['IN PROGRESS', true],
    'not an option' => ['Bananas', false],
]);
