<?php

declare(strict_types=1);

use App\Actions\Task\NotifyTaskAssignees;
use App\Features\OnboardSeed;
use App\Mail\TaskAssignedMail;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

mutates(NotifyTaskAssignees::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Mail::fake();
});

it('emails a newly assigned user when their email channel is on', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $assignee = User::factory()->create();
    $owner->currentTeam->users()->attach($assignee, ['role' => 'editor']);
    $assignee->update(['notification_preferences' => ['task_assigned' => ['email' => true]]]);

    $task = Task::factory()->for($owner->currentTeam)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id]);
    defer()->invoke();

    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $m): bool => $m->hasTo($assignee->email) && $m->teamName === $owner->currentTeam->name);
});

it('does not email when the email channel is off (default)', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $assignee = User::factory()->create();
    $owner->currentTeam->users()->attach($assignee, ['role' => 'editor']);

    $task = Task::factory()->for($owner->currentTeam)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id]);
    defer()->invoke();

    Mail::assertNothingQueued();
});

it('skips the in-app notification when the in-app channel is off', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $assignee = User::factory()->create();
    $owner->currentTeam->users()->attach($assignee, ['role' => 'editor']);
    $assignee->update(['notification_preferences' => ['task_assigned' => ['in_app' => false]]]);

    $task = Task::factory()->for($owner->currentTeam)->create(['title' => 'X']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id]);
    defer()->invoke();

    expect($assignee->fresh()->notifications()->count())->toBe(0);
});

it('renders the task title, workspace, and view link in html and text', function (): void {
    $mail = new TaskAssignedMail('Follow up', 'https://app.relaticle.test/tasks/1', 'Acme');

    $mail->assertHasSubject(__('mail.task_assigned.subject', ['title' => 'Follow up']));
    $mail->assertSeeInHtml(__('mail.task_assigned.preheader', ['team' => 'Acme']));
    $mail->assertSeeInHtml(__('mail.task_assigned.heading'));
    $mail->assertSeeInHtml('Follow up');
    $mail->assertSeeInHtml(__('mail.footer.reason.assignee', ['team' => 'Acme']));
    $mail->assertSeeInText(__('mail.task_assigned.cta').': https://app.relaticle.test/tasks/1');
});
