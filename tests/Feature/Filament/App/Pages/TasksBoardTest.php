<?php

declare(strict_types=1);

use App\Enums\CustomFields\TaskField;
use App\Filament\Actions\CreateTaskAction;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use App\Support\ChecklistHtml;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\Grouping;
use Relaticle\Flowforge\Lane;

mutates(TasksBoard::class, CreateTaskAction::class, ChecklistHtml::class, MoveCardAction::class);

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
        ->assertMountedActionModalSee(__('filament/pages/boards.move_to.current'))
        ->call('callMountedAction', ['to' => (string) $inProgress->getKey()])
        ->assertHasNoActionErrors()
        ->assertNotified(__('filament/pages/boards.move_to.moved', ['column' => 'In progress']));

    $storedValue = $task->fresh()->customFieldValues()
        ->where('custom_field_id', $this->statusField->getKey())
        ->value($this->statusField->getValueColumn());

    expect($storedValue)->toBe($inProgress->getKey());
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

it('points the navigation link at the view the user opened last', function (): void {
    expect(TaskResource::getNavigationUrl())->toBe(TaskResource::getUrl('index'));

    livewire(TasksBoard::class)->assertOk();

    expect(TaskResource::getNavigationUrl())->toBe(TaskResource::getUrl('board'));

    livewire(ManageTasks::class)->assertOk();

    expect(TaskResource::getNavigationUrl())->toBe(TaskResource::getUrl('index'));
});

it('falls back to the list when the remembered view no longer exists', function (): void {
    TaskResource::rememberViewMode('timeline');

    expect(TaskResource::getNavigationUrl())->toBe(TaskResource::getUrl('index'));
});

it('falls back to the list when the board the user opened last is no longer available', function (): void {
    TaskResource::rememberViewMode('board');

    $this->statusField->update(['active' => false]);

    expect(TaskResource::getNavigationUrl())->toBe(TaskResource::getUrl('index'));
});

it('links the sidebar to the board on the next page after the user opens it', function (): void {
    $this->get(TaskResource::getUrl('board'))->assertOk();

    $this->get(CompanyResource::getUrl('index'))
        ->assertOk()
        ->assertSee('href="'.TaskResource::getUrl('board').'"', escape: false);
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
