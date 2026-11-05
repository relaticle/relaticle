<?php

declare(strict_types=1);

use App\Enums\DeletionReason;
use App\Enums\Plan;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Cloud\Billing\Commands\ScheduleInactiveTrialDeletionsCommand;
use Relaticle\Cloud\Billing\Filament\Billing;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletionWarningMail;
use Relaticle\Cloud\Billing\Queries\InactiveTrialWorkspacesQuery;

mutates(ScheduleInactiveTrialDeletionsCommand::class);
mutates(InactiveTrialWorkspacesQuery::class);
mutates(InactiveWorkspaceDeletionWarningMail::class);

const INACTIVE_PAUSED_AT = '2026-11-01 10:00:00';

function inactivePausedWorkspace(array $attributes = [], string $timezone = 'UTC'): Workspace
{
    $owner = User::factory()->create(['timezone' => $timezone, 'last_login_at' => null]);

    return Workspace::factory()->create([
        'name' => 'Acme',
        'user_id' => $owner->id,
        'personal_workspace' => true,
        'plan' => Plan::Free,
        'pro_trial_used_at' => CarbonImmutable::parse(INACTIVE_PAUSED_AT)->subDays(14),
        'trial_ends_at' => null,
        'paused_at' => INACTIVE_PAUSED_AT,
        'hosted_free_grandfathered_at' => null,
        ...$attributes,
    ]);
}

function inactivePausedAt(): CarbonImmutable
{
    return CarbonImmutable::parse(INACTIVE_PAUSED_AT);
}

function runInactivityCommand(): void
{
    test()->artisan('billing:schedule-inactive-deletions')->assertSuccessful();
}

it('schedules a paused trial 46 days in and warns the owner once', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();

    $this->travelTo(inactivePausedAt()->addDays(46));
    runInactivityCommand();

    $workspace->refresh();

    expect($workspace->scheduled_deletion_at?->toDateTimeString())->toBe(inactivePausedAt()->addDays(60)->toDateTimeString())
        ->and($workspace->deletion_reason)->toBe(DeletionReason::Inactivity);

    Mail::assertQueued(InactiveWorkspaceDeletionWarningMail::class, 1);
    Mail::assertQueued(InactiveWorkspaceDeletionWarningMail::class, fn (InactiveWorkspaceDeletionWarningMail $mail): bool => $mail->hasTo($workspace->owner->email)
        && $mail->workspace->is($workspace));
});

it('waits until the deletion date is 14 days away', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();

    $this->travelTo(inactivePausedAt()->addDays(45));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull()
        ->and($workspace->deletion_reason)->toBeNull();

    Mail::assertNothingQueued();
});

it('never warns twice for the same countdown', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();

    $this->travelTo(inactivePausedAt()->addDays(46));
    runInactivityCommand();
    $scheduledFor = $workspace->refresh()->scheduled_deletion_at;

    $this->travelTo(inactivePausedAt()->addDays(47));
    runInactivityCommand();

    Mail::assertQueued(InactiveWorkspaceDeletionWarningMail::class, 1);
    expect($workspace->refresh()->scheduled_deletion_at?->equalTo($scheduledFor))->toBeTrue();
});

it('restarts the clock from a member sign-in', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $member = User::factory()->create(['last_login_at' => inactivePausedAt()->addDays(30)]);
    $workspace->users()->attach($member, ['role' => 'admin']);

    $this->travelTo(inactivePausedAt()->addDays(46));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();

    $this->travelTo(inactivePausedAt()->addDays(30 + 46));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at?->toDateTimeString())->toBe(inactivePausedAt()->addDays(30 + 60)->toDateTimeString());
});

it('restarts the clock from an owner sign-in on day 59 and schedules nothing before day 105', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $workspace->owner->forceFill(['last_login_at' => inactivePausedAt()->addDays(59)])->save();

    $this->travelTo(inactivePausedAt()->addDays(60));
    runInactivityCommand();

    $this->travelTo(inactivePausedAt()->addDays(59 + 45));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();
    Mail::assertNothingQueued();

    $this->travelTo(inactivePausedAt()->addDays(59 + 46));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at?->toDateTimeString())->toBe(inactivePausedAt()->addDays(59 + 60)->toDateTimeString());
    Mail::assertQueued(InactiveWorkspaceDeletionWarningMail::class, 1);
});

it('ignores a sign-in that happened before the workspace was paused', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $workspace->owner->forceFill(['last_login_at' => inactivePausedAt()->subDays(5)])->save();

    $this->travelTo(inactivePausedAt()->addDays(46));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at?->toDateTimeString())->toBe(inactivePausedAt()->addDays(60)->toDateTimeString());
});

it('gives a late-found workspace a full 14 days instead of a past date', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();

    $this->travelTo(inactivePausedAt()->addDays(100));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at?->toDateTimeString())->toBe(now()->addDays(14)->toDateTimeString());
    Mail::assertQueued(InactiveWorkspaceDeletionWarningMail::class, 1);
});

/** @return array<string, Closure(Workspace): mixed> */
function inactiveExclusions(): array
{
    return [
        'subscribed' => function (Workspace $workspace): void {
            $workspace->forceFill(['plan' => Plan::Pro])->save();
            $workspace->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_live', 'stripe_status' => 'active', 'stripe_price' => 'price_pro_monthly_test', 'quantity' => 1]);
        },
        'grandfathered' => fn (Workspace $workspace): bool => $workspace->forceFill(['hosted_free_grandfathered_at' => now()->subYear()])->save(),
        'charged once and since canceled' => fn (Workspace $workspace): Model => $workspace->subscriptions()->create(['type' => 'default', 'stripe_id' => 'sub_past', 'stripe_status' => 'canceled', 'stripe_price' => 'price_pro_monthly_test', 'quantity' => 1, 'ends_at' => inactivePausedAt()->addDays(20)]),
        'owed an unpaid managed invoice' => fn (Workspace $workspace): Model => $workspace->subscriptions()->create(['type' => 'managed_pending', 'stripe_id' => 'sub_managed', 'stripe_status' => 'incomplete', 'stripe_price' => 'price_pro_monthly_test', 'quantity' => 1]),
        'on a trial that ended but is not paused yet' => fn (Workspace $workspace): bool => $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => inactivePausedAt()->addDays(90)])->save(),
        'bought a credit pack' => fn (Workspace $workspace): Model => AiCreditTransaction::factory()->create(['workspace_id' => $workspace->getKey(), 'type' => AiCreditType::Purchase]),
        'scheduled on request' => fn (Workspace $workspace): bool => $workspace->forceFill(['scheduled_deletion_at' => inactivePausedAt()->addDays(120), 'deletion_reason' => DeletionReason::Requested])->save(),
        'never paused' => fn (Workspace $workspace): bool => $workspace->forceFill(['paused_at' => null])->save(),
        'still trialing' => fn (Workspace $workspace): bool => $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => inactivePausedAt()->addDays(130)])->save(),
        'without an owner' => fn (Workspace $workspace): bool => $workspace->forceFill(['user_id' => (string) Str::ulid()])->save(),
    ];
}

foreach (inactiveExclusions() as $reason => $arrange) {
    it("leaves out a workspace that is {$reason}", function () use ($arrange): void {
        Mail::fake();
        $workspace = inactivePausedWorkspace();
        $arrange($workspace);

        $this->travelTo(inactivePausedAt()->addDays(100));
        runInactivityCommand();

        expect($workspace->refresh()->deletion_reason)->not->toBe(DeletionReason::Inactivity);
        Mail::assertNothingQueued();
    });
}

it('counts only the owner and members of this workspace as sign-ins', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $stranger = User::factory()->create(['last_login_at' => inactivePausedAt()->addDays(44)]);
    inactivePausedWorkspace()->users()->attach($stranger, ['role' => 'admin']);

    $this->travelTo(inactivePausedAt()->addDays(46));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at?->toDateTimeString())->toBe(inactivePausedAt()->addDays(60)->toDateTimeString());
});

it('does not overwrite an owner request that lands after the workspace was read', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $requestedFor = inactivePausedAt()->addDays(120);

    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced, $workspace, $requestedFor): void {
        if ($raced || ! str_contains($query->sql, 'max("last_login_at")')) {
            return;
        }

        $raced = true;
        DB::table('workspaces')->where('id', $workspace->getKey())->update(['scheduled_deletion_at' => $requestedFor, 'deletion_reason' => DeletionReason::Requested->value]);
    });

    $this->travelTo(inactivePausedAt()->addDays(46));
    runInactivityCommand();

    expect($raced)->toBeTrue();
    expect($workspace->refresh()->deletion_reason)->toBe(DeletionReason::Requested);
    Mail::assertNothingQueued();
});

it('runs daily at 02:00', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();

    $expressions = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'billing:schedule-inactive-deletions'))
        ->map(fn (Event $event): string => $event->expression)
        ->values()
        ->all();

    expect($expressions)->toBe(['0 2 * * *']);
});

it('keeps the date and the reason of a deletion the owner requested', function (): void {
    Mail::fake();
    $requestedFor = inactivePausedAt()->addDays(120);
    $workspace = inactivePausedWorkspace(['scheduled_deletion_at' => $requestedFor, 'deletion_reason' => DeletionReason::Requested]);

    $this->travelTo(inactivePausedAt()->addDays(100));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at?->toDateTimeString())->toBe($requestedFor->toDateTimeString())
        ->and($workspace->deletion_reason)->toBe(DeletionReason::Requested);
});

it('writes the warning for the owner with dates in their timezone', function (): void {
    $workspace = inactivePausedWorkspace(timezone: 'Pacific/Auckland');
    $deletesAt = CarbonImmutable::parse('2026-12-31 23:00:00');
    $mail = new InactiveWorkspaceDeletionWarningMail($workspace, $deletesAt, CarbonImmutable::parse('2026-11-20 12:00:00'));

    $mail->assertHasSubject('Acme will be deleted on Jan 1, 2027');
    $mail->assertSeeInHtml('Acme will be deleted on Jan 1, 2027');
    $mail->assertSeeInHtml('Acme is paused, and no one has signed in since Nov 21, 2026.');
    $mail->assertSeeInHtml('We delete paused workspaces after 60 days. On Jan 1, 2027 we will delete Acme and every record in it. This cannot be undone.');
    $mail->assertSeeInHtml('To keep the workspace, choose a plan before then.');
    $mail->assertSeeInHtml('Signing in also keeps it for another 60 days.');
    $mail->assertSeeInHtml('Questions? Reply to this email.');
    $mail->assertSeeInHtml('No one has signed in since Nov 21, 2026. Choose a plan or sign in to keep it.');
    $mail->assertSeeInHtml(__('mail.footer.reason.owner', ['workspace' => 'Acme']));
    $mail->assertSeeInText('Choose a plan: '.Billing::getUrl(panel: 'app', tenant: $workspace));
});
