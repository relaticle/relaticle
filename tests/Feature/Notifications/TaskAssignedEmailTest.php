<?php

declare(strict_types=1);

use App\Actions\Task\NotifyTaskAssignees;
use App\Features\OnboardSeed;
use App\Mail\TaskAssignedMail;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Mail::fake();
});

it('emails a newly assigned user when their email channel is on', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $assignee = User::factory()->create();
    $owner->currentTeam->users()->attach($assignee, ['role' => 'editor']);
    $assignee->update(['notification_preferences' => ['taskAssignedEmail' => true]]);

    $task = Task::factory()->for($owner->currentTeam)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task);
    defer()->invoke();

    Mail::assertQueued(TaskAssignedMail::class, fn (TaskAssignedMail $m): bool => $m->hasTo($assignee->email));
});

it('does not email when the email channel is off (default)', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $assignee = User::factory()->create();
    $owner->currentTeam->users()->attach($assignee, ['role' => 'editor']);

    $task = Task::factory()->for($owner->currentTeam)->create(['title' => 'Follow up']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task);
    defer()->invoke();

    Mail::assertNothingQueued();
});

it('skips the in-app notification when the in-app channel is off', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $assignee = User::factory()->create();
    $owner->currentTeam->users()->attach($assignee, ['role' => 'editor']);
    $assignee->update(['notification_preferences' => ['taskAssignedInApp' => false]]);

    $task = Task::factory()->for($owner->currentTeam)->create(['title' => 'X']);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task);
    defer()->invoke();

    expect($assignee->fresh()->notifications()->count())->toBe(0);
});
