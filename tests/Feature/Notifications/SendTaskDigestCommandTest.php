<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Features\TaskDigestEmails;
use App\Mail\TaskDigestMail;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Feature::define(TaskDigestEmails::class, true);
    Mail::fake();
});

function digestCmdSetDue(Task $task, string $teamId, DateTimeInterface $dueAt): void
{
    $field = DB::table('custom_fields')->where('tenant_id', $teamId)
        ->where('entity_type', 'task')->where('code', 'due_date')->first();
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'entity_type' => 'task',
        'entity_id' => $task->id,
        'custom_field_id' => trim((string) $field->id),
        'tenant_id' => $teamId,
        'datetime_value' => $dueAt->format('Y-m-d H:i:s'),
    ]);
}

function userWithDueTask(string $timezone, bool $digestEmail = true): User
{
    $user = User::factory()->withPersonalTeam()->create(['timezone' => $timezone]);

    if (! $digestEmail) {
        $user->update(['notification_preferences' => ['task_digest' => ['email' => false]]]);
    }

    $task = Task::factory()->for($user->currentTeam)->create(['title' => 'Due task']);
    $task->assignees()->attach($user);
    digestCmdSetDue($task, $user->currentTeam->id, now()->subDay());

    return $user;
}

it('queues a digest for a user at 08:00 local time', function (): void {
    $this->travelTo(Carbon::parse('2026-06-29 08:00:00', 'UTC'));
    $user = userWithDueTask('UTC');

    $this->artisan('notifications:send-task-digest')->assertSuccessful();

    Mail::assertQueued(TaskDigestMail::class, function (TaskDigestMail $mail) use ($user): bool {
        return $mail->hasTo($user->email) && $mail->mailer === 'postmark_broadcast';
    });
});

it('does not queue outside 08:00 local time', function (): void {
    $this->travelTo(Carbon::parse('2026-06-29 09:00:00', 'UTC'));
    userWithDueTask('UTC');

    $this->artisan('notifications:send-task-digest')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('filters recipients by timezone so only users at their local 08:00 are queued', function (): void {
    // At 23:00 UTC, Asia/Tokyo (UTC+9) is 08:00 the next day, while UTC is 23:00.
    $this->travelTo(Carbon::parse('2026-06-29 23:00:00', 'UTC'));
    $tokyo = userWithDueTask('Asia/Tokyo');
    $utc = userWithDueTask('UTC');

    $this->artisan('notifications:send-task-digest')->assertSuccessful();

    Mail::assertQueued(TaskDigestMail::class, fn (TaskDigestMail $mail): bool => $mail->hasTo($tokyo->email));
    Mail::assertNotQueued(TaskDigestMail::class, fn (TaskDigestMail $mail): bool => $mail->hasTo($utc->email));
});

it('suppresses the digest when the user has no due tasks', function (): void {
    $this->travelTo(Carbon::parse('2026-06-29 08:00:00', 'UTC'));
    User::factory()->withPersonalTeam()->create(['timezone' => 'UTC']);

    $this->artisan('notifications:send-task-digest')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('does not queue when the digest email channel is off', function (): void {
    $this->travelTo(Carbon::parse('2026-06-29 08:00:00', 'UTC'));
    userWithDueTask('UTC', digestEmail: false);

    $this->artisan('notifications:send-task-digest')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('does not send when the rollout feature is inactive', function (): void {
    Feature::define(TaskDigestEmails::class, false);
    $this->travelTo(Carbon::parse('2026-06-29 08:00:00', 'UTC'));
    userWithDueTask('UTC');

    $this->artisan('notifications:send-task-digest')->assertSuccessful();

    Mail::assertNothingQueued();
});
