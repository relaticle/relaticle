<?php

declare(strict_types=1);

use App\Actions\Views\ReorderBoardStages;
use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\TaskField;
use App\Enums\WorkspaceRole;
use App\Filament\Actions\CreateTaskAction;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\TablePreference;
use App\Models\Task;
use App\Models\User;
use App\Support\ChecklistHtml;
use Closure;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\Grouping;
use Relaticle\Flowforge\Lane;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Helpers\WorkspaceCustomField;

mutates(ReorderBoardStages::class, TasksBoard::class, CreateTaskAction::class, ChecklistHtml::class, MoveCardAction::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);

    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->statusField = CustomField::query()
        ->forEntity(Task::class)
        ->where('code', TaskField::STATUS)
        ->first();
});

function getTaskBoard(): Board
{
    $component = livewire(TasksBoard::class);

    return $component->instance()->getBoard();
}

it('can render the board page', function (): void {
    livewire(TasksBoard::class)
        ->assertOk();
});

it('displays tasks in the correct board columns', function (): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');
    $done = $this->statusField->options->firstWhere('name', 'Done');

    $todoTask = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $todoTask->saveCustomFieldValue($this->statusField, $todo->getKey());

    $doneTask = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $doneTask->saveCustomFieldValue($this->statusField, $done->getKey());

    $board = getTaskBoard();

    expect($board->getBoardRecords((string) $todo->getKey())->pluck('id'))
        ->toContain($todoTask->id)
        ->and($board->getBoardRecords((string) $done->getKey())->pluck('id'))
        ->toContain($doneTask->id);
});

it('does not show tasks from other workspaces', function (): void {
    $otherUser = User::factory()->withWorkspace()->create();
    $otherTask = Task::factory()->for($otherUser->currentWorkspace)->create();

    $board = getTaskBoard();
    $allRecordIds = collect($this->statusField->options)
        ->flatMap(fn ($opt): Collection => $board->getBoardRecords((string) $opt->getKey()))
        ->pluck('id');

    expect($allRecordIds)->not->toContain($otherTask->id);
});

it('renders the board when a task has multiple assignees', function (): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());

    $secondMember = User::factory()->create();
    $this->workspace->users()->attach($secondMember);
    $task->assignees()->attach([$this->user->id, $secondMember->id]);

    livewire(TasksBoard::class)->assertOk();
});

it('shows the view switcher linking list and board views', function (): void {
    livewire(ManageTasks::class)
        ->assertSeeHtml(TaskResource::getUrl('board'));

    livewire(TasksBoard::class)
        ->assertSeeHtml(TaskResource::getUrl('index'));
});

it('redirects the legacy board url to the resource board page', function (): void {
    $this->get(route('filament.app.tasks-board.redirect', ['tenant' => $this->workspace->slug]))
        ->assertRedirect(TaskResource::getUrl('board'));
});

it('renders the board and the list heading when the status field has no options', function (): void {
    $this->statusField->options()->delete();

    livewire(TasksBoard::class)->assertOk();

    livewire(ManageTasks::class)
        ->assertOk()
        ->assertSeeHtml(TaskResource::getUrl('board'));
});

it('resolves the status custom field once per request across access check and board render', function (): void {
    DB::enableQueryLog();

    livewire(TasksBoard::class)->assertOk();

    $statusFieldLookups = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'custom_fields')
            && in_array(TaskField::STATUS->value, $query['bindings'], true));

    DB::disableQueryLog();

    expect($statusFieldLookups)->toHaveCount(1);
});

it('moves a card between columns via moveCard', function (): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');
    $inProgress = $this->statusField->options->firstWhere('name', 'In progress');

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());

    livewire(TasksBoard::class)
        ->call('moveCard', (string) $task->id, (string) $inProgress->getKey())
        ->assertDispatched('kanban-card-moved');

    $updatedValue = $task->fresh()->customFieldValues()
        ->where('custom_field_id', $this->statusField->getKey())
        ->value($this->statusField->getValueColumn());

    expect($updatedValue)->toBe($inProgress->getKey());
});

it('moves a card to the status picked from its move to sheet', function (): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');
    $inProgress = $this->statusField->options->firstWhere('name', 'In progress');

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());

    livewire(TasksBoard::class)
        ->call('mountAction', 'moveTo', [], ['recordKey' => (string) $task->id])
        ->assertMountedActionModalSee(['To do', 'In progress', 'Done'])
        ->call('callMountedAction', ['to' => (string) $inProgress->getKey()])
        ->assertHasNoActionErrors()
        ->assertNotified(__('filament/pages/boards.move_to.moved', ['column' => 'In progress']));

    $storedValue = $task->fresh()->customFieldValues()
        ->where('custom_field_id', $this->statusField->getKey())
        ->value($this->statusField->getValueColumn());

    expect($storedValue)->toBe($inProgress->getKey());
});

it('keeps move to off a card a viewer cannot change', function (): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');
    $done = $this->statusField->options->firstWhere('name', 'Done');

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());

    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(TasksBoard::class)
        ->call('mountAction', 'moveTo', [], ['recordKey' => (string) $task->id])
        ->assertActionNotMounted()
        ->call('callMountedAction', ['to' => (string) $done->getKey()]);

    $storedValue = $task->fresh()->customFieldValues()
        ->where('custom_field_id', $this->statusField->getKey())
        ->value($this->statusField->getValueColumn());

    expect($storedValue)->toBe($todo->getKey());
});

it('names the move to choices for a screen reader', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(TasksBoard::class)
        ->call('mountAction', 'moveTo', [], ['recordKey' => (string) $task->id])
        ->assertMountedActionModalSeeHtml('role="radiogroup" aria-label="Move to"');
});

it('moves a card between two cards of another column', function (): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');
    $inProgress = $this->statusField->options->firstWhere('name', 'In progress');

    [$above, $below, $task] = Task::factory()->count(3)->recycle([$this->user, $this->workspace])->create()->all();
    $above->saveCustomFieldValue($this->statusField, $inProgress->getKey());
    $below->saveCustomFieldValue($this->statusField, $inProgress->getKey());
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());
    $above->forceFill(['order_column' => 1000])->saveQuietly();
    $below->forceFill(['order_column' => 2000])->saveQuietly();

    livewire(TasksBoard::class)
        ->call('moveCard', (string) $task->id, (string) $inProgress->getKey(), (string) $above->id, (string) $below->id)
        ->assertDispatched('kanban-card-moved');

    $task->refresh();
    $storedValue = $task->customFieldValues()
        ->where('custom_field_id', $this->statusField->getKey())
        ->value($this->statusField->getValueColumn());

    expect($storedValue)->toBe($inProgress->getKey())
        ->and((float) $task->order_column)->toBeGreaterThan(1000.0)->toBeLessThan(2000.0);
});

it('moves a card to another assignee lane and keeps its other assignees', function (): void {
    $this->withoutDefer();

    $todo = $this->statusField->options->firstWhere('name', 'To do');
    [$leaving, $joining, $staying] = User::factory()->count(3)->create()->all();
    $this->workspace->users()->attach([$leaving->id, $joining->id, $staying->id], ['role' => 'member']);

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());
    $task->assignees()->attach([$leaving->id, $staying->id]);
    Task::factory()->recycle([$this->user, $this->workspace])->create()->assignees()->attach($joining);

    livewire(TasksBoard::class, ['groupBy' => 'assignee'])
        ->call('moveCard', (string) $task->id, (string) $todo->getKey(), null, null, (string) $leaving->id, (string) $joining->id)
        ->assertDispatched('kanban-card-moved');

    expect($task->assignees()->pluck('users.id')->sort()->values()->all())
        ->toBe(collect([$joining->id, $staying->id])->sort()->values()->all())
        ->and($joining->notifications()->count())->toBe(1)
        ->and($staying->notifications()->count())->toBe(0);
});

it('moves a card into the unassigned lane', function (): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');
    $assignee = User::factory()->create();
    $this->workspace->users()->attach($assignee, ['role' => 'member']);

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());
    $task->assignees()->attach($assignee);

    livewire(TasksBoard::class, ['groupBy' => 'assignee'])
        ->call('moveCard', (string) $task->id, (string) $todo->getKey(), null, null, (string) $assignee->id, Grouping::EMPTY_LANE)
        ->assertDispatched('kanban-card-moved');

    expect($task->assignees()->exists())->toBeFalse();
});

it('offers an assignee lane only for workspace members', function (): void {
    $member = User::factory()->create();
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $formerMember = User::factory()->create();

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->assignees()->attach([$member->id, $formerMember->id]);

    $board = livewire(TasksBoard::class, ['groupBy' => 'assignee'])->instance()->getBoard();
    $laneNames = collect($board->getActiveGrouping()->getLanes($board->getQuery()))
        ->map(fn (Lane $lane): string => $lane->getName());

    expect($laneNames)->toContain($member->id)
        ->not->toContain($formerMember->id);
});

it('rejects a move to a status option the board does not own', function (string $targetKind): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());

    User::factory()->withWorkspace()->create();
    $foreignOptionId = CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Task::class)
        ->where('code', TaskField::STATUS)
        ->where('tenant_id', '!=', $this->workspace->getKey())
        ->firstOrFail()
        ->options()
        ->withoutGlobalScopes()
        ->firstOrFail()
        ->getKey();

    $targetId = $targetKind === 'foreign' ? (string) $foreignOptionId : 'not-an-option';

    livewire(TasksBoard::class)
        ->call('moveCard', (string) $task->id, $targetId)
        ->assertNotified(__('flowforge::flowforge.unknown_column'))
        ->assertNotDispatched('kanban-card-moved');

    $storedValue = $task->fresh()->customFieldValues()
        ->where('custom_field_id', $this->statusField->getKey())
        ->value($this->statusField->getValueColumn());

    expect($storedValue)->toBe($todo->getKey());
})->with(['foreign workspace option' => 'foreign', 'garbage id' => 'garbage']);

it('rejects a card created in a status option the board does not own', function (string $targetKind): void {
    User::factory()->withWorkspace()->create();
    $foreignOptionId = CustomField::query()
        ->withoutGlobalScopes()
        ->forEntity(Task::class)
        ->where('code', TaskField::STATUS)
        ->where('tenant_id', '!=', $this->workspace->getKey())
        ->firstOrFail()
        ->options()
        ->withoutGlobalScopes()
        ->firstOrFail()
        ->getKey();

    $targetId = $targetKind === 'foreign' ? (string) $foreignOptionId : 'not-an-option';

    livewire(TasksBoard::class)
        ->assertActionDisabled(TestAction::make('create')->arguments(['column' => $targetId]))
        ->call('mountAction', 'create', ['column' => $targetId])
        ->set('mountedActions.0.data.title', 'Rejected task')
        ->call('callMountedAction')
        ->assertOk();

    expect(Task::query()->where('title', 'Rejected task')->exists())->toBeFalse();
})->with(['foreign workspace option' => 'foreign', 'garbage id' => 'garbage']);

it('creates a card in a status option the board owns', function (): void {
    $inProgress = $this->statusField->options->firstWhere('name', 'In progress');

    livewire(TasksBoard::class)
        ->call('mountAction', 'create', ['column' => (string) $inProgress->getKey()])
        ->set('mountedActions.0.data.title', 'Accepted task')
        ->call('callMountedAction')
        ->assertOk();

    $task = Task::query()->where('title', 'Accepted task')->firstOrFail();
    $storedValue = $task->customFieldValues()
        ->where('custom_field_id', $this->statusField->getKey())
        ->value($this->statusField->getValueColumn());

    expect($storedValue)->toBe($inProgress->getKey());
});

it('opens the edit action when a card is clicked', function (): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());

    $component = livewire(TasksBoard::class);

    expect($component->instance()->getBoard()->getCardAction())->toBe('edit');

    $component
        ->call('mountAction', 'edit', [], ['recordKey' => (string) $task->id])
        ->assertSet('mountedActions.0.data.title', $task->title);
});

/**
 * The badge answers "is this due today?", which is a question about the viewer's
 * calendar. Bounded on the server clock instead, a task lands in the wrong bucket for
 * every user far enough east or west. The board is the primary planning view, so the
 * wrong bucket is the wrong plan for the day.
 */
it('buckets the due-date badge against the user calendar, not the server clock', function (): void {
    $this->travelTo(Date::parse('2026-08-18 13:50:00', 'UTC'));

    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $dueField = CustomField::query()
        ->forEntity(Task::class)
        ->where('code', TaskField::DUE_DATE)
        ->first();

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->saveCustomFieldValue($this->statusField, $this->statusField->options->firstWhere('name', 'To do')->getKey());

    // 23:30 UTC on the 18th is 08:30 on the 19th in Tokyo. At the moment above the
    // Tokyo calendar already reads the 18th at 22:50, so this is due *tomorrow* there
    // while a UTC reader would still call it today.
    $task->saveCustomFieldValue($dueField, Date::parse('2026-08-18 23:30:00', 'UTC'));

    livewire(TasksBoard::class)
        ->assertSee('Due Tomorrow')
        ->assertDontSee('Due Today');
});

it('keeps a card\'s assignees and status when only its title is edited', function (): void {
    $assignee = User::factory()->create();
    $this->workspace->users()->attach($assignee, ['role' => 'member']);
    $inProgress = $this->statusField->options->firstWhere('name', 'In progress');

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Draft the proposal']);
    $task->saveCustomFieldValue($this->statusField, $inProgress->getKey());
    $task->assignees()->attach($assignee);

    livewire(TasksBoard::class)
        ->call('mountAction', 'edit', [], ['recordKey' => (string) $task->id])
        ->set('mountedActions.0.data.title', 'Send the proposal')
        ->call('callMountedAction')
        ->assertHasNoActionErrors();

    $task->refresh();

    expect($task->title)->toBe('Send the proposal')
        ->and($task->assignees->modelKeys())->toBe([$assignee->id])
        ->and(DB::table('custom_field_values')->where('entity_id', $task->id)->where('custom_field_id', $this->statusField->id)->value('string_value'))->toBe((string) $inProgress->getKey());
});

it('notifies only the member newly assigned from a card edit', function (): void {
    $this->withoutDefer();

    $kept = User::factory()->create();
    $added = User::factory()->create();
    $this->workspace->users()->attach([$kept->id, $added->id], ['role' => 'member']);

    $task = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $task->saveCustomFieldValue($this->statusField, $this->statusField->options->firstWhere('name', 'To do')->getKey());
    $task->assignees()->attach($kept);

    livewire(TasksBoard::class)
        ->call('mountAction', 'edit', [], ['recordKey' => (string) $task->id])
        ->set('mountedActions.0.data.assignees', [$kept->id, $added->id])
        ->call('callMountedAction')
        ->assertHasNoActionErrors();

    expect($task->refresh()->assignees->modelKeys())->toEqualCanonicalizing([$kept->id, $added->id])
        ->and($added->notifications()->count())->toBe(1)
        ->and($kept->notifications()->count())->toBe(0);
});

it('notifies the assignee of a task added from a board column', function (): void {
    $this->withoutDefer();

    $assignee = User::factory()->create();
    $this->workspace->users()->attach($assignee, ['role' => 'admin']);

    $column = $this->statusField->options()->firstOrFail();

    livewire(TasksBoard::class)
        ->callAction(
            TestAction::make('create')->arguments(['column' => (string) $column->getKey()]),
            data: ['title' => 'Prepare the renewal deck', 'assignees' => [$assignee->id]],
        )
        ->assertHasNoActionErrors();

    expect(Task::query()->where('title', 'Prepare the renewal deck')->exists())->toBeTrue()
        ->and($assignee->notifications()->count())->toBe(1);
});

function tasksBoardChecklistHtml(): string
{
    return '<ul data-type="taskList">'
        .'<li data-checked="true" data-type="taskItem"><label><input type="checkbox" checked="checked"><span></span></label><div><p>Call Bob</p></div></li>'
        .'<li data-checked="false" data-type="taskItem"><label><input type="checkbox"><span></span></label><div><p>Send quote</p></div></li>'
        .'</ul>';
}

function tasksBoardCardWithDescription(mixed $test, string $description): Task
{
    $descriptionField = CustomField::query()
        ->forEntity(Task::class)
        ->where('code', TaskField::DESCRIPTION)
        ->first();

    $task = Task::factory()->recycle([$test->user, $test->workspace])->create();
    $task->saveCustomFieldValue($test->statusField, $test->statusField->options->firstWhere('name', 'To do')->getKey());
    $task->saveCustomFieldValue($descriptionField, $description);

    return $task;
}

it('shows checklist progress on a task card', function (): void {
    tasksBoardCardWithDescription($this, tasksBoardChecklistHtml());

    livewire(TasksBoard::class)
        ->assertSeeHtml('data-checklist-progress')
        ->assertSee('1/2');
});

it('reads a card description as separate words and leaves the ticks to the badge', function (): void {
    tasksBoardCardWithDescription($this, '<p>Agenda</p><p>Venue</p>'.tasksBoardChecklistHtml());

    livewire(TasksBoard::class)
        ->assertSee('Agenda Venue Call Bob Send quote')
        ->assertDontSee('[x]')
        ->assertDontSee('[ ]');
});

it('shows markup typed into a card description as text', function (): void {
    tasksBoardCardWithDescription($this, '<p>&lt;b&gt;literal&lt;/b&gt;</p>');

    livewire(TasksBoard::class)
        ->assertSeeHtml('&lt;b&gt;literal&lt;/b&gt;')
        ->assertDontSeeHtml('<b>literal</b>');
});

it('turns the checklist badge green once every item is ticked', function (): void {
    tasksBoardCardWithDescription($this, str_replace('data-checked="false"', 'data-checked="true"', tasksBoardChecklistHtml()));

    livewire(TasksBoard::class)
        ->assertSee('2/2')
        ->assertSeeHtml('fi-color-success')
        ->assertSeeHtml('2 of 2 items done');
});

it('keeps an unfinished checklist badge gray with its count in a tooltip', function (): void {
    tasksBoardCardWithDescription($this, tasksBoardChecklistHtml());

    livewire(TasksBoard::class)
        ->assertSee('1/2')
        ->assertDontSeeHtml('fi-color-success')
        ->assertSeeHtml('1 of 2 items done');
});

it('names the checklist badge from the lang file', function (): void {
    app('translator')->addLines(['filament/pages/boards.tasks.checklist_progress' => 'Steps done'], 'en');
    tasksBoardCardWithDescription($this, tasksBoardChecklistHtml());

    livewire(TasksBoard::class)
        ->assertSee('Steps done');
});

it('shows no checklist badge on a card without a checklist', function (): void {
    tasksBoardCardWithDescription($this, '<p>Plain</p>');

    livewire(TasksBoard::class)
        ->assertSee('Plain')
        ->assertDontSeeHtml('data-checklist-progress');
});

it('reads checklist progress without a query per card', function (): void {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        livewire(TasksBoard::class)->assertSee('1/2');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    tasksBoardCardWithDescription($this, tasksBoardChecklistHtml());
    livewire(TasksBoard::class);
    $withOneCard = $countQueries();

    foreach (range(1, 4) as $ignored) {
        tasksBoardCardWithDescription($this, tasksBoardChecklistHtml());
    }

    expect($countQueries())->toBe($withOneCard);
});

function taskInStatus(object $test, string $title, string $status): Task
{
    $task = Task::factory()->recycle([$test->user, $test->workspace])->create(['title' => $title]);
    $task->saveCustomFieldValue($test->statusField, $test->statusField->options->firstWhere('name', $status)->getKey());

    return $task;
}

function taskStatusCardIds(Testable $component, string $status): array
{
    $option = CustomFieldOption::query()->withoutGlobalScopes()->where('name', $status)->firstOrFail();

    return $component->instance()->getBoard()->getBoardRecords((string) $option->getKey())->pluck('id')->all();
}

it('narrows the cards with a rule from the bar', function (string $field): void {
    $mate = User::factory()->create();
    $this->workspace->users()->attach($mate->id, ['role' => 'member']);
    $priority = CustomField::query()->forEntity(Task::class)->where('code', TaskField::PRIORITY)->firstOrFail();

    $keep = taskInStatus($this, 'Keep me', 'To do');
    $drop = taskInStatus($this, 'Drop me', 'To do');
    $keep->assignees()->attach($mate);
    $keep->saveCustomFieldValue($priority, $priority->options->first()->getKey());

    $rule = match ($field) {
        'title' => ['field' => 'title', 'condition' => 'contains', 'value' => 'Keep'],
        'assignees' => ['field' => 'assignees', 'condition' => 'is_any_of', 'value' => [$mate->getKey()]],
        'custom_fields.priority' => ['field' => 'custom_fields.priority', 'condition' => 'is_not_empty'],
    };

    $component = livewire(TasksBoard::class);

    expect(taskStatusCardIds($component, 'To do'))->toContain($keep->id, $drop->id);

    $component->call('setFilterRules', [$rule]);

    expect(taskStatusCardIds($component, 'To do'))->toBe([$keep->id]);
})->with(['a native field' => 'title', 'a relation' => 'assignees', 'a custom attribute' => 'custom_fields.priority']);

it('shows every card again when the bar is cleared', function (): void {
    $keep = taskInStatus($this, 'Keep me', 'To do');
    $drop = taskInStatus($this, 'Drop me', 'To do');

    $component = livewire(TasksBoard::class)
        ->call('setFilterRules', [['field' => 'title', 'condition' => 'contains', 'value' => 'Keep']]);

    expect($component->instance()->hasFilterBarState())->toBeTrue();

    $component->call('clearFilterBar');

    expect(taskStatusCardIds($component, 'To do'))->toContain($keep->id, $drop->id)
        ->and($component->instance()->hasFilterBarState())->toBeFalse();
});

it('makes assignee lanes when view settings groups by assignee', function (): void {
    livewire(TasksBoard::class)
        ->call('setBoardGrouping', 'assignee')
        ->assertSet('groupBy', 'assignee');
});

it('removes the lanes when the grouping is cleared', function (): void {
    livewire(TasksBoard::class)
        ->call('setBoardGrouping', 'assignee')
        ->call('setBoardGrouping', null)
        ->assertSet('groupBy', null);
});

it('ignores a grouping the board does not have', function (): void {
    livewire(TasksBoard::class)
        ->call('setBoardGrouping', 'status')
        ->assertSet('groupBy', null);
});

it('offers the bar no deleted control and no groups, and lists the lanes in view settings', function (): void {
    $component = livewire(TasksBoard::class);
    $data = $component->instance()->getFilterBarData();

    expect($data['deletable'])->toBeFalse()
        ->and($data['groups'])->toBe([])
        ->and($data['grouping'])->toBeNull();

    $component->assertSeeHtml('data-board-grouping="assignee"')
        ->assertSeeHtml('data-board-grouping="none"')
        ->assertSee(__('filament/pages/boards.view_settings.lanes'))
        ->assertSee(__('filament/pages/boards.view_settings.no_lanes'));
});

function tasksToSort(object $test): void
{
    $dueDate = WorkspaceCustomField::byCode($test->workspace->getKey(), 'task', 'due_date');

    foreach ([
        ['Bravo', Date::parse('2026-01-01'), '2026-03-03', 1000],
        ['Charlie', Date::parse('2026-01-03'), '2026-03-01', 2000],
        ['Alpha', Date::parse('2026-01-02'), '2026-03-02', 3000],
    ] as [$title, $createdAt, $due, $position]) {
        $task = taskInStatus($test, $title, 'To do');
        $task->forceFill(['created_at' => $createdAt, 'order_column' => $position])->saveQuietly();
        $task->saveCustomFieldValue($dueDate, $due);
    }
}

function taskStatusTitles(Testable $component, string $status): array
{
    $option = CustomFieldOption::query()->withoutGlobalScopes()->where('name', $status)->firstOrFail();

    return $component->instance()->getBoard()->getBoardRecords((string) $option->getKey())->pluck('title')->all();
}

it('orders the cards of a column by the sort from the bar', function (string $column, string $direction, array $expected): void {
    tasksToSort($this);

    $component = livewire(TasksBoard::class)
        ->call('setFilterBarSorts', [['column' => $column, 'direction' => $direction]]);

    expect(taskStatusTitles($component, 'To do'))->toBe($expected);
})->with([
    'newest first' => ['created_at', 'desc', ['Charlie', 'Alpha', 'Bravo']],
    'title a to z' => ['title', 'asc', ['Alpha', 'Bravo', 'Charlie']],
    'latest due date first' => ['custom_fields.due_date', 'desc', ['Bravo', 'Alpha', 'Charlie']],
    'earliest due date first' => ['custom_fields.due_date', 'asc', ['Charlie', 'Alpha', 'Bravo']],
]);

it('keeps the hand order while no sort is set', function (): void {
    tasksToSort($this);

    $component = livewire(TasksBoard::class);

    expect(taskStatusTitles($component, 'To do'))->toBe(['Bravo', 'Charlie', 'Alpha']);

    $component->call('setFilterBarSorts', [['column' => 'title', 'direction' => 'asc']])->call('setFilterBarSorts', []);

    expect(taskStatusTitles($component, 'To do'))->toBe(['Bravo', 'Charlie', 'Alpha'])
        ->and($component->get('boardSort'))->toBeNull();
});

it('offers the sort fields the shared sort language allows', function (): void {
    $keys = array_column(livewire(TasksBoard::class)->instance()->getFilterBarData()['sortable'], 'key');

    expect($keys)->toContain('title', 'created_at', 'updated_at', 'custom_fields.due_date', 'custom_fields.priority')
        ->and($keys)->not->toContain('order_column');
});

it('gives each sort field the label and icon the filter catalog gives it', function (): void {
    $data = livewire(TasksBoard::class)->instance()->getFilterBarData();
    $fields = collect($data['fields'])->keyBy('key');

    foreach ($data['sortable'] as $sortable) {
        expect($fields->get($sortable['key']))->not->toBeNull()
            ->and($sortable['label'])->toBe($fields[$sortable['key']]['label'])
            ->and($sortable['icon'])->toBe($fields[$sortable['key']]['icon']);
    }
});

it('ignores a sort the board does not offer', function (array $sorts): void {
    livewire(TasksBoard::class)
        ->call('setFilterBarSorts', $sorts)
        ->assertSet('boardSort', null);
})->with([
    'a column the board does not offer' => [[['column' => 'order_column', 'direction' => 'asc']]],
    'a direction that is not one' => [[['column' => 'title', 'direction' => 'sideways']]],
    'not a list of sorts' => [['title']],
]);

it('reads the sort from the url and ignores a garbage one', function (): void {
    tasksToSort($this);

    $sorted = Livewire::withQueryParams(['sort' => 'created_at:desc'])->test(TasksBoard::class);

    expect(taskStatusTitles($sorted, 'To do'))->toBe(['Charlie', 'Alpha', 'Bravo']);

    foreach (['x;drop', 'order_column:asc', 'title:sideways', ':', 'custom_fields.gone:asc'] as $garbage) {
        $component = Livewire::withQueryParams(['sort' => $garbage])->test(TasksBoard::class)->assertOk();

        expect(taskStatusTitles($component, 'To do'))->toBe(['Bravo', 'Charlie', 'Alpha'])
            ->and($component->instance()->getFilterBarData()['sorts'])->toBe([]);
    }
});

it('keeps the hand order and offers no sort while lanes are shown', function (): void {
    tasksToSort($this);
    Task::query()->get()->each(fn (Task $task) => $task->assignees()->attach($this->user));

    $component = livewire(TasksBoard::class)
        ->call('setFilterBarSorts', [['column' => 'title', 'direction' => 'asc']])
        ->call('setBoardGrouping', 'assignee');

    $board = $component->instance()->getBoard();
    $lane = collect($board->getLanes($board->getActiveGrouping()))->first(fn (Lane $lane): bool => $lane->getName() === (string) $this->user->getKey());

    expect($component->instance()->getFilterBarData()['sortable'])->toBe([])
        ->and($component->get('boardSort'))->toBe('title:asc')
        ->and($board->getLaneRecords($lane, 50)->pluck('title')->all())->toBe(['Bravo', 'Charlie', 'Alpha']);

    $component->call('setBoardGrouping', null);

    expect(taskStatusTitles($component, 'To do'))->toBe(['Alpha', 'Bravo', 'Charlie']);
});

it('refuses a drop inside the same column while sorted', function (): void {
    tasksToSort($this);
    $card = Task::query()->where('title', 'Bravo')->firstOrFail();
    $status = (string) $this->statusField->options->firstWhere('name', 'To do')->getKey();
    $others = Task::query()->whereKeyNot($card->getKey())->pluck('id');

    livewire(TasksBoard::class)
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']])
        ->call('moveCard', (string) $card->id, $status, (string) $others[0], (string) $others[1])
        ->assertNotified(__('filament/pages/boards.sorted_no_reorder', ['field' => 'Created at']))
        ->assertNotDispatched('kanban-card-moved');

    expect((float) $card->fresh()->order_column)->toBe(1000.0);
});

it('still moves a card to another status while sorted', function (): void {
    tasksToSort($this);
    $card = Task::query()->where('title', 'Bravo')->firstOrFail();
    $inProgress = $this->statusField->options->firstWhere('name', 'In progress');

    $component = livewire(TasksBoard::class)
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']])
        ->call('moveCard', (string) $card->id, (string) $inProgress->getKey())
        ->assertDispatched('kanban-card-moved');

    expect(taskStatusTitles($component, 'In progress'))->toBe(['Bravo']);
});

it('leaves the deleted records half of the bar inert on a board', function (): void {
    $component = livewire(TasksBoard::class)->call('setDeletedRecordsFilter', 'only');

    expect($component->instance()->getFilterBarData()['deleted'])->toBeNull()
        ->and($component->instance()->hasFilterBarState())->toBeFalse();

    $fromUrl = Livewire::withQueryParams(['filters' => ['trashed' => ['value' => '0']]])->test(TasksBoard::class);

    expect($fromUrl->instance()->getFilterBarData()['deleted'])->toBeNull()
        ->and($fromUrl->instance()->hasFilterBarState())->toBeFalse();
});

it('labels every field on the board as the list does', function (): void {
    $board = collect(livewire(TasksBoard::class)->instance()->getFilterBarData()['fields'])->pluck('label', 'key');
    $list = collect(livewire(ManageTasks::class)->instance()->getFilterBarData()['fields'])->pluck('label', 'key');

    expect($board->all())->toEqual($list->all());
});

it('draws the bar and none of the old toolbar', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::bootCurrentPanel();

    livewire(TasksBoard::class)
        ->assertSeeHtml('data-filter-bar')
        ->assertDontSeeHtml('ff-grouping')
        ->assertDontSeeHtml('fi-ta-search-field')
        ->assertDontSeeHtml('fi-ta-filters-dropdown');
});

it('ignores a search that arrives in the url', function (): void {
    $card = taskInStatus($this, 'Findable', 'To do');

    $component = Livewire::withQueryParams(['search' => 'zzqx-no-match'])->test(TasksBoard::class);

    expect(taskStatusCardIds($component, 'To do'))->toContain($card->id);
});

it('renders a board whose url carries garbage filters', function (string $query): void {
    $this->get(TasksBoard::getUrl(['tenant' => $this->workspace]).'?'.$query)->assertSuccessful();
})->with([
    'trashed scalar' => 'filters[trashed]=x',
    'whole value a string' => 'filters=x',
    'conditions scalar' => 'filters[conditions]=x',
    'creation source nested junk' => 'filters[creation_source][values]=x',
]);

it('turns an old assignees link into a chip', function (): void {
    $mate = User::factory()->create();
    $this->workspace->users()->attach($mate->id, ['role' => 'member']);
    $keep = taskInStatus($this, 'Keep me', 'To do');
    $drop = taskInStatus($this, 'Drop me', 'To do');
    $keep->assignees()->attach($mate);

    $component = Livewire::withQueryParams(['filters' => ['assignees' => ['values' => [$mate->getKey()]]]])->test(TasksBoard::class);

    expect(array_column($component->instance()->getFilterBarData()['rules'], 'field'))->toBe(['assignees'])
        ->and(taskStatusCardIds($component, 'To do'))->toBe([$keep->id]);
});

/**
 * @return list<string>
 */
function statusIds(CustomField $field): array
{
    return CustomFieldOption::query()->withoutGlobalScopes()->where('custom_field_id', $field->getKey())->orderBy('sort_order')->pluck('id')->map(strval(...))->all();
}

function freshTasksBoard(): Testable
{
    Once::flush();

    return livewire(TasksBoard::class);
}

it('lists the statuses in the order the field settings give them by default', function (): void {
    expect(freshTasksBoard()->instance()->getBoard()->getColumnIdentifiers())->toBe(statusIds($this->statusField));
});

it('keeps a reordered status list for the member on the same page and on a new visit', function (): void {
    $reversed = array_reverse(statusIds($this->statusField));

    $component = livewire(TasksBoard::class)->call('reorderBoardStages', $reversed);

    expect($component->instance()->getBoard()->getColumnIdentifiers())->toBe($reversed)
        ->and(freshTasksBoard()->instance()->getBoard()->getColumnIdentifiers())->toBe($reversed);
});

it('leaves another member\'s task board in the field order', function (): void {
    $other = User::factory()->create();
    $this->workspace->users()->attach($other, ['role' => WorkspaceRole::Member->value]);
    $other->switchWorkspace($this->workspace);

    livewire(TasksBoard::class)->call('reorderBoardStages', array_reverse(statusIds($this->statusField)));

    $this->actingAs($other);

    expect(freshTasksBoard()->instance()->getBoard()->getColumnIdentifiers())->toBe(statusIds($this->statusField));
});

it('never writes the status options when statuses are reordered', function (): void {
    $before = CustomFieldOption::query()->where('custom_field_id', $this->statusField->getKey())->pluck('sort_order', 'id')->all();

    livewire(TasksBoard::class)->call('reorderBoardStages', array_reverse(statusIds($this->statusField)));

    expect(CustomFieldOption::query()->where('custom_field_id', $this->statusField->getKey())->pluck('sort_order', 'id')->all())->toBe($before);
});

it('stores a status order once each and rejects an opportunity stage', function (): void {
    $order = statusIds($this->statusField);
    $stage = CustomField::query()->forEntity(Opportunity::class)->where('code', 'stage')->firstOrFail();

    $state = resolve(ReorderBoardStages::class)->execute($this->user, $this->workspace, CrmEntity::Task, [$order[1], $order[1], $order[0]]);

    expect($state->stageOrder)->toBe([$order[1], $order[0]])
        ->and(fn () => resolve(ReorderBoardStages::class)->execute($this->user, $this->workspace, CrmEntity::Task, statusIds($stage)))
        ->toThrow(HttpException::class);
});

function taskBoardPreferenceWrites(Closure $callback): int
{
    $writes = 0;
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/^(update|insert into) "table_preferences"/', $query->sql) === 1) {
            $writes++;
        }
    });
    $callback();

    return $writes;
}

function rememberedTaskBoard(object $test, BoardStateData $state): TablePreference
{
    return TablePreference::factory()->create([
        'user_id' => $test->user->getKey(),
        'workspace_id' => $test->workspace->getKey(),
        'entity' => CrmEntity::Task,
        'board_state' => $state,
    ]);
}

it('remembers a filter and the lanes set on the plain board for the next visit', function (): void {
    taskInStatus($this, 'Keep me', 'To do');
    $rule = ['field' => 'title', 'condition' => 'contains', 'value' => 'Keep'];

    livewire(TasksBoard::class)
        ->call('setFilterRules', [$rule])
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']])
        ->call('setBoardGrouping', 'assignee');

    $next = freshTasksBoard();

    expect($next->get('groupBy'))->toBe('assignee')
        ->and($next->get('boardSort'))->toBe('created_at:desc')
        ->and($next->instance()->getFilterBarData()['rules'])->toHaveCount(1)
        ->and(TablePreference::query()->sole()->board_state->grouping)->toBe('assignee');
});

it('writes nothing for a request that changes nothing', function (): void {
    $preference = rememberedTaskBoard($this, new BoardStateData(grouping: 'assignee'));
    $updatedAt = $preference->updated_at;
    $component = freshTasksBoard();

    $this->travelTo(now()->addHour());
    $writes = taskBoardPreferenceWrites(function () use ($component): void {
        $component->call('$refresh')->call('setBoardGrouping', 'assignee')->call('setFilterBarSorts', []);
    });

    expect($writes)->toBe(0)
        ->and($preference->refresh()->updated_at)->toEqual($updatedAt);
});

it('writes once when statuses are reordered', function (): void {
    rememberedTaskBoard($this, new BoardStateData(grouping: 'assignee'));
    $component = freshTasksBoard();

    $writes = taskBoardPreferenceWrites(fn () => $component->call('reorderBoardStages', array_reverse(statusIds($this->statusField))));

    expect($writes)->toBe(1);
});

it('keeps the filter when a sort is set', function (): void {
    $keep = taskInStatus($this, 'Keep me', 'To do');
    taskInStatus($this, 'Drop me', 'To do');

    $component = livewire(TasksBoard::class)
        ->call('setFilterRules', [['field' => 'title', 'condition' => 'contains', 'value' => 'Keep']])
        ->call('setFilterBarSorts', [['column' => 'created_at', 'direction' => 'desc']]);

    expect($component->instance()->getFilterBarData()['rules'])->toHaveCount(1)
        ->and(taskStatusCardIds($component, 'To do'))->toBe([$keep->id]);
});
