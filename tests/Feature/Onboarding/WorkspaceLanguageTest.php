<?php

declare(strict_types=1);

use App\Actions\Task\CompleteTask;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFields\TaskField;
use App\Enums\OnboardingUseCase;
use App\Enums\OptionColor;
use App\Features\OnboardSeed;
use App\Listeners\CreateWorkspaceCustomFields;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Crm\CrmSummaryQuery;
use App\Services\Notifications\DigestService;
use App\Support\CustomFieldOptionPlan;
use App\Support\Locales;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\MyTasksService;
use Relaticle\OnboardSeed\OnboardSeedManager;
use Relaticle\OnboardSeed\Support\FixtureLoader;

mutates(CreateWorkspaceCustomFields::class, OnboardSeedManager::class, TaskField::class, OpportunityField::class, FixtureLoader::class, CompleteTask::class);

beforeEach(function (): void {
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
        ->and($stageNames->last())->toBe('Verloren')
        ->and($stageNames)->toHaveCount(10)
        ->and(collect(wlOptionsOf($workspace, 'task', 'status'))->pluck('name')->all())->toBe(['Zu erledigen', 'In Bearbeitung', 'Erledigt']);
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
    File::ensureDirectoryExists("{$this->fixtures}/sales/companies");
    File::put("{$this->fixtures}/sales/companies/airbnb.yaml", "name: Airbnb\n");
    File::ensureDirectoryExists("{$this->fixtures}/de/sales/notes");
    File::put("{$this->fixtures}/de/sales/notes/note.yaml", <<<'YAML'
title: Plattform fuer Gastgeber
noteable_type: company
noteable_key: airbnb
custom_fields:
  body: <p>Notiz</p>
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

    expect(Note::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->sole()->title)->toBe('Plattform fuer Gastgeber')
        ->and($task->title)->toBe('Erstgespraech mit Brian')
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

function wlAddSecondDoneOption(Workspace $workspace): string
{
    $field = CustomField::withoutGlobalScopes()
        ->where('tenant_id', $workspace->id)->where('entity_type', 'task')->where('code', 'status')->sole();
    $id = Str::lower((string) Str::ulid());

    DB::table('custom_field_options')->insert([
        'id' => $id,
        'custom_field_id' => $field->id,
        'tenant_id' => $workspace->id,
        'name' => 'Erledigt',
        'sort_order' => 3,
    ]);

    return $id;
}

it('treats only the earliest done option as done when a field has two', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->personalWorkspace();
    $doneId = wlStatusOptionId($workspace, 'Done');
    $extraId = wlAddSecondDoneOption($workspace);
    wlTaskWithStatus($user, $workspace, 'open', 'To do', now()->subDay());
    wlTaskWithStatus($user, $workspace, 'finished', 'Done', now()->subDay());
    $this->actingAs($user);

    $digest = resolve(DigestService::class)->forWorkspace($user, $workspace);
    $mine = resolve(MyTasksService::class)->forUser($user, $workspace);
    $summary = resolve(CrmSummaryQuery::class)->get($user)['tasks'];

    expect(collect($digest->overdue)->pluck('title')->all())->toBe(['open'])
        ->and($mine->pluck('title')->all())->toBe(['open'])
        ->and($summary['overdue'])->toBe(1);

    $field = CustomField::withoutGlobalScopes()->where('tenant_id', $workspace->id)->where('entity_type', 'task')->where('code', 'status')->sole()->load('options');

    expect(CustomFieldOptionPlan::locksOption($field, $doneId))->toBeTrue()
        ->and(CustomFieldOptionPlan::locksOption($field, $extraId))->toBeFalse();
});

it('completes a task with the earliest done option when a field has two', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->personalWorkspace();
    $doneId = wlStatusOptionId($workspace, 'Done');
    wlAddSecondDoneOption($workspace);
    $task = wlTaskWithStatus($user, $workspace, 'send', 'To do', now()->addDay());

    $this->actingAs($user);
    resolve(CompleteTask::class)->execute($user, $task);

    expect(DB::table('custom_field_values')->where('entity_id', $task->id)->pluck('string_value')->all())->toContain($doneId);
});

it('names the workspace done option when it refuses to rename it', function (): void {
    [, $workspace] = wlGermanWorkspace();
    $field = CustomField::withoutGlobalScopes()->where('tenant_id', $workspace->id)->where('entity_type', 'task')->where('code', 'status')->sole()->load('options');

    $options = $field->options->sortBy('sort_order')->map(fn (CustomFieldOption $option): array => [
        'id' => $option->id,
        'name' => $option->name === 'Erledigt' ? 'Fertig' : $option->name,
        'was' => $option->name,
    ])->values()->all();

    expect(fn () => CustomFieldOptionPlan::validated($field, ['options' => $options, 'removed' => []]))
        ->toThrow(ValidationException::class, '"Erledigt"');
});

it('gives a German creator the fundraising stages in German and seeds against them', function (): void {
    Feature::define(OnboardSeed::class, true);
    $user = User::factory()->create(['locale' => 'de']);

    $workspace = onboardWorkspace($user, ['name' => 'Fonds'], ['onboarding_use_case' => OnboardingUseCase::Fundraising->value, 'onboarding_context' => ['early_stage']]);

    $names = collect(wlOptionsOf($workspace, 'opportunity', 'stage'));
    $partner = $names->sole(fn (CustomFieldOption $option): bool => $option->name === 'Partnertreffen');
    $stored = DB::table('custom_field_values')->where('custom_field_id', $partner->custom_field_id)->pluck('string_value')->all();

    expect($names->pluck('name')->first())->toBe('Zielinvestor')
        ->and($partner->settings->color)->toBe(OptionColor::Amber->value)
        ->and($stored)->toContain($partner->id)
        ->and(Locales::within('de', fn (): array => array_keys(OnboardingUseCase::Fundraising->pipelineStages())))->toContain('Partnertreffen');
});

it('seeds stages by the english label even when the language changed after the workspace was created', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create(['locale' => 'de']);
    $workspace = $user->personalWorkspace();
    $user->forceFill(['locale' => 'en'])->save();
    File::ensureDirectoryExists("{$this->fixtures}/sales/tasks");
    File::put("{$this->fixtures}/sales/tasks/call.yaml", <<<'YAML'
title: Discovery call with Brian
custom_fields:
  status: To do
YAML);
    FixtureLoader::setBasePath($this->fixtures);

    resolve(OnboardSeedManager::class)->generateFor($user->refresh(), $workspace);

    $task = Task::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->sole();

    expect(DB::table('custom_field_values')->where('entity_id', $task->id)->pluck('string_value')->all())
        ->toContain(wlStatusOptionId($workspace, 'Zu erledigen'));
});
