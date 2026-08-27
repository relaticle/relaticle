<?php

declare(strict_types=1);

use App\Enums\CustomFields\TaskField;
use App\Filament\Resources\TaskResource;
use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Filament\Resources\TaskResource\Pages\TasksBoard;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Relaticle\Flowforge\Board;

mutates(TasksBoard::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);

    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

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

    $todoTask = Task::factory()->recycle([$this->user, $this->team])->create();
    $todoTask->saveCustomFieldValue($this->statusField, $todo->getKey());

    $doneTask = Task::factory()->recycle([$this->user, $this->team])->create();
    $doneTask->saveCustomFieldValue($this->statusField, $done->getKey());

    $board = getTaskBoard();

    expect($board->getBoardRecords((string) $todo->getKey())->pluck('id'))
        ->toContain($todoTask->id)
        ->and($board->getBoardRecords((string) $done->getKey())->pluck('id'))
        ->toContain($doneTask->id);
});

it('does not show tasks from other teams', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherTask = Task::factory()->for($otherUser->currentTeam)->create();

    $board = getTaskBoard();
    $allRecordIds = collect($this->statusField->options)
        ->flatMap(fn ($opt): Collection => $board->getBoardRecords((string) $opt->getKey()))
        ->pluck('id');

    expect($allRecordIds)->not->toContain($otherTask->id);
});

it('renders the board when a task has multiple assignees', function (): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');

    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());

    $secondMember = User::factory()->create();
    $this->team->users()->attach($secondMember);
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
    $this->get(route('filament.app.tasks-board.redirect', ['tenant' => $this->team->slug]))
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

    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $task->saveCustomFieldValue($this->statusField, $todo->getKey());

    livewire(TasksBoard::class)
        ->call('moveCard', (string) $task->id, (string) $inProgress->getKey())
        ->assertDispatched('kanban-card-moved');

    $updatedValue = $task->fresh()->customFieldValues()
        ->where('custom_field_id', $this->statusField->getKey())
        ->value($this->statusField->getValueColumn());

    expect($updatedValue)->toBe($inProgress->getKey());
});

it('opens the edit action when a card is clicked', function (): void {
    $todo = $this->statusField->options->firstWhere('name', 'To do');

    $task = Task::factory()->recycle([$this->user, $this->team])->create();
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
 * every user far enough east or west — the board is the primary planning view, so the
 * wrong bucket is the wrong plan for the day.
 */
it('buckets the due-date badge against the user calendar, not the server clock', function (): void {
    $this->travelTo(Carbon::parse('2026-08-18 13:50:00', 'UTC'));

    $this->user->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $dueField = CustomField::query()
        ->forEntity(Task::class)
        ->where('code', TaskField::DUE_DATE)
        ->first();

    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $task->saveCustomFieldValue($this->statusField, $this->statusField->options->firstWhere('name', 'To do')->getKey());

    // 23:30 UTC on the 18th is 08:30 on the 19th in Tokyo. At the moment above the
    // Tokyo calendar already reads the 18th at 22:50, so this is due *tomorrow* there
    // while a UTC reader would still call it today.
    $task->saveCustomFieldValue($dueField, Carbon::parse('2026-08-18 23:30:00', 'UTC'));

    livewire(TasksBoard::class)
        ->assertSee('Due Tomorrow')
        ->assertDontSee('Due Today');
});
