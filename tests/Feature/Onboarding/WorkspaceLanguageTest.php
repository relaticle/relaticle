<?php

declare(strict_types=1);

use App\Actions\Task\CompleteTask;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Enums\OptionColor;
use App\Listeners\CreateWorkspaceCustomFields;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Crm\CrmSummaryQuery;
use App\Services\Notifications\DigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Relaticle\Chat\Services\MyTasksService;
use Relaticle\OnboardSeed\OnboardSeedManager;
use Relaticle\OnboardSeed\Support\FixtureLoader;

mutates(CreateWorkspaceCustomFields::class, OnboardSeedManager::class, TaskField::class, OpportunityField::class, FixtureLoader::class, CompleteTask::class);

beforeEach(function (): void {
    Lang::addLines([
        'enums/custom-fields.opportunity.stage.name' => 'Phase',
        'enums/custom-fields.opportunity.stage.options.prospecting' => 'Akquise',
        'enums/custom-fields.opportunity.stage.options.closed_won' => 'Gewonnen',
        'enums/custom-fields.task.status.name' => 'Status',
        'enums/custom-fields.task.status.options.to_do' => 'Zu erledigen',
        'enums/custom-fields.task.status.options.in_progress' => 'In Arbeit',
        'enums/custom-fields.task.status.options.done' => 'Erledigt',
        'enums/custom-fields.task.priority.options.high' => 'Hoch',
    ], 'de');

    $this->fixtures = sys_get_temp_dir().'/'.Str::uuid()->toString();
});

afterEach(function (): void {
    File::deleteDirectory($this->fixtures);
});

function wlGermanWorkspace(): array
{
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'de']);

    return [$user, $user->personalWorkspace()];
}

function wlOptionsOf(Workspace $workspace, string $entity, string $code): array
{
    return CustomField::withoutGlobalScopes()
        ->where('tenant_id', $workspace->id)
        ->where('entity_type', $entity)
        ->where('code', $code)
        ->sole()
        ->options()
        ->withoutGlobalScopes()
        ->orderBy('sort_order')
        ->get()
        ->all();
}

function wlStatusOptionId(Workspace $workspace, string $name): string
{
    return collect(wlOptionsOf($workspace, 'task', 'status'))
        ->sole(fn (CustomFieldOption $option): bool => $option->name === $name)
        ->id;
}

function wlTaskWithStatus(User $user, Workspace $workspace, string $title, string $statusName, DateTimeInterface $due): Task
{
    $task = Task::factory()->for($workspace)->create(['title' => $title]);
    $task->assignees()->attach($user);

    $field = fn (string $code): string => trim((string) DB::table('custom_fields')
        ->where('tenant_id', $workspace->id)->where('entity_type', 'task')->where('code', $code)->value('id'));

    $value = fn (string $code, array $column) => DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => $field($code),
        'tenant_id' => $workspace->id,
        ...$column,
    ]);

    $value('due_date', ['datetime_value' => $due->format('Y-m-d H:i:s')]);
    $value('status', ['string_value' => wlStatusOptionId($workspace, $statusName)]);

    return $task;
}

it('creates built-in attributes and stages in the creator language', function (): void {
    [, $workspace] = wlGermanWorkspace();

    $stage = CustomField::withoutGlobalScopes()
        ->where('tenant_id', $workspace->id)->where('entity_type', 'opportunity')->where('code', 'stage')->sole();
    $stageNames = collect(wlOptionsOf($workspace, 'opportunity', 'stage'))->pluck('name');

    expect($stage->name)->toBe('Phase')
        ->and($stageNames->first())->toBe('Akquise')
        ->and($stageNames->last())->toBe('Closed Lost')
        ->and($stageNames)->toHaveCount(10)
        ->and(collect(wlOptionsOf($workspace, 'task', 'status'))->pluck('name')->all())->toBe(['Zu erledigen', 'In Arbeit', 'Erledigt']);
});

it('keeps stage colours in every language', function (): void {
    [, $workspace] = wlGermanWorkspace();
    $english = User::factory()->withPersonalWorkspace()->create()->personalWorkspace();

    $colours = fn (Workspace $workspace): array => collect(wlOptionsOf($workspace, 'opportunity', 'stage'))
        ->map(fn (CustomFieldOption $option): ?string => $option->settings->color)
        ->all();

    expect($colours($workspace))->toBe($colours($english))
        ->and($colours($workspace)[0])->toBe(OptionColor::Purple->value);

    $done = collect(wlOptionsOf($workspace, 'task', 'status'))->sole(fn (CustomFieldOption $option): bool => $option->name === 'Erledigt');

    expect($done->settings->color)->toBe(OptionColor::Green->value);
});

it('seeds sample notes and tasks in the creator language', function (): void {
    [$user, $workspace] = wlGermanWorkspace();
    File::ensureDirectoryExists("{$this->fixtures}/de/sales/tasks");
    File::put("{$this->fixtures}/de/sales/tasks/call.yaml", <<<'YAML'
title: Erstgespraech mit Brian
custom_fields:
  status: To do
  priority: High
YAML);
    File::ensureDirectoryExists("{$this->fixtures}/sales/tasks");
    File::put("{$this->fixtures}/sales/tasks/call.yaml", <<<'YAML'
title: Discovery call with Brian
custom_fields:
  status: To do
YAML);
    FixtureLoader::setBasePath($this->fixtures);

    expect(resolve(OnboardSeedManager::class)->generateFor($user, $workspace))->toBeTrue();

    $task = Task::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->sole();
    $stored = DB::table('custom_field_values')->where('entity_id', $task->id)->pluck('string_value')->all();

    expect($task->title)->toBe('Erstgespraech mit Brian')
        ->and($stored)->toContain(wlStatusOptionId($workspace, 'Zu erledigen'));
});

it('seeds the english fixtures when the creator language has no tree', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    File::ensureDirectoryExists("{$this->fixtures}/sales/tasks");
    File::put("{$this->fixtures}/sales/tasks/call.yaml", <<<'YAML'
title: Discovery call with Brian
custom_fields:
  status: To do
YAML);
    FixtureLoader::setBasePath($this->fixtures);

    resolve(OnboardSeedManager::class)->generateFor($user, $user->personalWorkspace());

    expect(Task::query()->withoutGlobalScopes()->where('workspace_id', $user->personalWorkspace()->id)->sole()->title)->toBe('Discovery call with Brian');
});

it('leaves existing workspaces untouched', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->personalWorkspace();

    $user->forceFill(['locale' => 'de'])->save();

    expect(collect(wlOptionsOf($workspace, 'task', 'status'))->pluck('name')->all())->toBe(['To do', 'In progress', 'Done']);
});

it('completes a task in a German workspace whose status reads Erledigt', function (): void {
    [$user, $workspace] = wlGermanWorkspace();
    $task = wlTaskWithStatus($user, $workspace, 'Angebot senden', 'Zu erledigen', now()->addDay());

    $this->actingAs($user);
    resolve(CompleteTask::class)->execute($user, $task);

    expect(DB::table('custom_field_values')->where('entity_id', $task->id)->pluck('string_value')->all())
        ->toContain(wlStatusOptionId($workspace, 'Erledigt'));
});

it('counts German done tasks in the digest, My Tasks and the CRM summary', function (): void {
    [$user, $workspace] = wlGermanWorkspace();
    wlTaskWithStatus($user, $workspace, 'offen', 'Zu erledigen', now()->subDay());
    wlTaskWithStatus($user, $workspace, 'fertig', 'Erledigt', now()->subDay());
    $this->actingAs($user);

    $digest = resolve(DigestService::class)->forWorkspace($user, $workspace);
    $mine = resolve(MyTasksService::class)->forUser($user, $workspace);
    $summary = resolve(CrmSummaryQuery::class)->get($user)['tasks'];

    expect(collect($digest->overdue)->pluck('title')->all())->toBe(['offen'])
        ->and($mine->pluck('title')->all())->toBe(['offen'])
        ->and($summary['overdue'])->toBe(1)
        ->and(TaskField::isDone('Erledigt'))->toBeTrue()
        ->and(TaskField::isDone('Done'))->toBeTrue()
        ->and(TaskField::isDone('Fertig'))->toBeFalse();
});
