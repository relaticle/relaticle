<?php

declare(strict_types=1);

use App\Actions\Jetstream\UpdateWorkspaceName;
use App\Actions\Task\NotifyTaskAssignees;
use App\Enums\Notifications\NotificationReason;
use App\Features\OnboardSeed;
use App\Mail\TaskAssignedMail;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Support\CurrentWorkspace;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;

mutates(NotifyTaskAssignees::class, TaskAssignedNotification::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

it('emails a newly assigned user when their email channel is on', function (): void {
    Notification::fake();

    $owner = User::factory()->withPersonalWorkspace()->create();
    $assignee = User::factory()->create();
    $owner->currentWorkspace->users()->attach($assignee, ['role' => 'member']);
    $assignee->update(['notification_preferences' => ['task_assigned' => ['email' => true]]]);

    $task = Task::factory()->for($owner->currentWorkspace)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $owner);

    Notification::assertSentTo($assignee, TaskAssignedNotification::class, function (TaskAssignedNotification $notification, array $channels) use ($assignee, $owner): bool {
        $mail = $notification->toMail($assignee);

        return in_array('mail', $channels, true)
            && $mail->hasTo($assignee->email)
            && $mail->workspaceName === $owner->currentWorkspace->name;
    });
});

it('does not email when the email channel is off (default)', function (): void {
    Notification::fake();

    $owner = User::factory()->withPersonalWorkspace()->create();
    $assignee = User::factory()->create();
    $owner->currentWorkspace->users()->attach($assignee, ['role' => 'member']);

    $task = Task::factory()->for($owner->currentWorkspace)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $owner);

    Notification::assertSentTo($assignee, TaskAssignedNotification::class, fn (TaskAssignedNotification $notification, array $channels): bool => $channels === ['database']);
});

it('skips the in-app notification when the in-app channel is off', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $assignee = User::factory()->create();
    $owner->currentWorkspace->users()->attach($assignee, ['role' => 'member']);
    $assignee->update(['notification_preferences' => ['task_assigned' => ['in_app' => false]]]);

    $task = Task::factory()->for($owner->currentWorkspace)->create(['title' => 'X']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $owner);

    expect($assignee->fresh()->notifications()->count())->toBe(0);
});

it('stores the workspace, subject, actor and reason on the row', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $assignee = User::factory()->create();
    $owner->currentWorkspace->users()->attach($assignee, ['role' => 'member']);

    $task = Task::factory()->for($owner->currentWorkspace)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $owner);

    $row = $assignee->notifications()->sole();

    expect($row->workspace_id)->toBe($owner->currentWorkspace->id)
        ->and($row->subject_type)->toBe('task')
        ->and($row->subject_id)->toBe($task->id)
        ->and($row->actor_id)->toBe($owner->id)
        ->and($row->reason)->toBe(NotificationReason::Assigned);
});

it('does not notify a member who assigns the task to themselves', function (): void {
    Notification::fake();

    $owner = User::factory()->withPersonalWorkspace()->create();
    $owner->update(['notification_preferences' => ['task_assigned' => ['email' => true]]]);

    $task = Task::factory()->for($owner->currentWorkspace)->create(['title' => 'Follow up']);
    $task->assignees()->attach($owner);

    resolve(NotifyTaskAssignees::class)->execute($task, [$owner->id], $owner);

    Notification::assertNothingSent();
});

it('skips a recipient who is no longer a member of the workspace', function (): void {
    Notification::fake();

    $owner = User::factory()->withPersonalWorkspace()->create();
    $assignee = User::factory()->create();
    $assignee->update(['notification_preferences' => ['task_assigned' => ['email' => true]]]);
    $owner->currentWorkspace->users()->attach($assignee, ['role' => 'member']);

    $task = Task::factory()->for($owner->currentWorkspace)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);
    $owner->currentWorkspace->users()->detach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $owner);

    Notification::assertNothingSent();
});

it('links to the task under the workspace slug at read time', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $oldSlug = $workspace->slug;
    $assignee = User::factory()->create();
    $workspace->users()->attach($assignee, ['role' => 'member']);

    $task = Task::factory()->for($workspace)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $owner);
    resolve(UpdateWorkspaceName::class)->update($owner, $workspace, ['name' => $workspace->name, 'slug' => 'renamed-workspace']);

    $row = $assignee->notifications()->sole();
    $url = resolve(CurrentWorkspace::class)
        ->within($workspace, fn (): ?string => TaskAssignedNotification::present($row)->getActions()[0]->getUrl());

    expect($url)->toContain('/renamed-workspace/')
        ->and($url)->not->toContain("/{$oldSlug}/");
});

it('offers no link once the task is deleted', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $assignee = User::factory()->create();
    $workspace->users()->attach($assignee, ['role' => 'member']);

    $task = Task::factory()->for($workspace)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $owner);
    $row = $assignee->notifications()->sole();
    $task->delete();

    $actions = resolve(CurrentWorkspace::class)
        ->within($workspace, fn (): array => TaskAssignedNotification::present($row)->getActions());

    expect($actions)->toBe([]);
});

it('sends inside the workspace of the notification', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $assignee = User::factory()->create();
    $workspace->users()->attach($assignee, ['role' => 'member']);

    $task = Task::factory()->for($workspace)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    $boundWorkspaceIds = [];
    Event::listen(NotificationSending::class, function () use (&$boundWorkspaceIds): void {
        $boundWorkspaceIds[] = resolve(CurrentWorkspace::class)->get()?->getKey();
    });
    resolve(CurrentWorkspace::class)->forget();

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $owner);

    expect($boundWorkspaceIds)->toBe([$workspace->id])
        ->and(resolve(CurrentWorkspace::class)->get())->toBeNull();
});

it('drops a queued send whose task was deleted before it ran', function (): void {
    Queue::fake();

    $owner = User::factory()->withPersonalWorkspace()->create();
    $assignee = User::factory()->create();
    $owner->currentWorkspace->users()->attach($assignee, ['role' => 'member']);

    $task = Task::factory()->for($owner->currentWorkspace)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $owner);

    $queue = new class extends SyncQueue
    {
        public function payloadFor(object $job): string
        {
            return $this->createPayload($job, 'default');
        }
    };
    $queue->setContainer(app());

    $queuedJob = new SyncJob(app(), $queue->payloadFor(Queue::pushed(SendQueuedNotifications::class)->first()), 'sync', 'default');
    $task->forceDelete();
    $queuedJob->fire();

    expect($queuedJob->isDeleted())->toBeTrue()
        ->and($queuedJob->hasFailed())->toBeFalse()
        ->and($assignee->notifications()->count())->toBe(0);
});

it('broadcasts a refresh to the assignee', function (): void {
    Event::fake([DatabaseNotificationsSent::class]);

    $owner = User::factory()->withPersonalWorkspace()->create();
    $assignee = User::factory()->create();
    $owner->currentWorkspace->users()->attach($assignee, ['role' => 'member']);

    $task = Task::factory()->for($owner->currentWorkspace)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $owner);

    Event::assertDispatched(
        DatabaseNotificationsSent::class,
        fn (DatabaseNotificationsSent $event): bool => $event->broadcastOn() === "private-App.Models.User.{$assignee->id}",
    );
});

it('renders the task title, workspace, and view link in html and text', function (): void {
    $mail = new TaskAssignedMail('Follow up', 'https://app.relaticle.test/tasks/1', 'Acme');

    $mail->assertHasSubject(__('mail.task_assigned.subject', ['title' => 'Follow up']));
    $mail->assertSeeInHtml(__('mail.task_assigned.preheader', ['workspace' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.task_assigned.heading'));
    $mail->assertSeeInHtml('Follow up');
    $mail->assertSeeInHtml(__('mail.footer.reason.assignee', ['workspace' => 'Acme']));
    $mail->assertSeeInText(__('mail.task_assigned.cta').': https://app.relaticle.test/tasks/1');
});
