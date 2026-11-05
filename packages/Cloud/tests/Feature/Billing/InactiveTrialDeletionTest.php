<?php

declare(strict_types=1);

use App\Enums\DeletionReason;
use App\Enums\Plan;
use App\Filament\Pages\CreateWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Cloud\Billing\Actions\CancelInactivityDeletion;
use Relaticle\Cloud\Billing\Commands\AnnounceInactivityRuleCommand;
use Relaticle\Cloud\Billing\Commands\ScheduleInactiveTrialDeletionsCommand;
use Relaticle\Cloud\Billing\Filament\Billing;
use Relaticle\Cloud\Billing\Listeners\CancelInactivityDeletionOnLogin;
use Relaticle\Cloud\Billing\Listeners\SendInactiveWorkspaceDeletedMail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletedMail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletionReminderMail;
use Relaticle\Cloud\Billing\Mail\InactiveWorkspaceDeletionWarningMail;
use Relaticle\Cloud\Billing\Mail\InactivityRuleNoticeMail;
use Relaticle\Cloud\Billing\Queries\InactiveTrialWorkspacesQuery;

mutates(ScheduleInactiveTrialDeletionsCommand::class);
mutates(AnnounceInactivityRuleCommand::class);
mutates(InactivityRuleNoticeMail::class);
mutates(InactiveTrialWorkspacesQuery::class);
mutates(InactiveWorkspaceDeletionWarningMail::class);
mutates(CancelInactivityDeletion::class);
mutates(CancelInactivityDeletionOnLogin::class);
mutates(InactiveWorkspaceDeletionReminderMail::class);
mutates(InactiveWorkspaceDeletedMail::class);
mutates(SendInactiveWorkspaceDeletedMail::class);

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

function inactivityRunOn(int $day): CarbonImmutable
{
    return inactivePausedAt()->startOfDay()->addDays($day)->setTime(2, 0);
}

function runInactivityCommand(): void
{
    test()->artisan('billing:schedule-inactive-deletions')->assertSuccessful();
}

it('schedules a paused trial on the 46th daily run and warns the owner once', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();

    $this->travelTo(inactivityRunOn(46));
    runInactivityCommand();

    $workspace->refresh();

    expect($workspace->scheduled_deletion_at?->toDateTimeString())->toBe(inactivePausedAt()->addDays(60)->toDateTimeString())
        ->and($workspace->deletion_reason)->toBe(DeletionReason::Inactivity);

    Mail::assertQueued(InactiveWorkspaceDeletionWarningMail::class, 1);
    Mail::assertQueued(InactiveWorkspaceDeletionWarningMail::class, fn (InactiveWorkspaceDeletionWarningMail $mail): bool => $mail->hasTo($workspace->owner->email)
        && $mail->workspace->is($workspace));
});

it('waits until the deletion date is within 15 days of the daily run', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();

    $this->travelTo(inactivityRunOn(45));
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

    $this->travelTo(inactivityRunOn(46));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();

    $this->travelTo(inactivityRunOn(30 + 45));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();

    $this->travelTo(inactivityRunOn(30 + 46));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at?->toDateTimeString())->toBe(inactivePausedAt()->addDays(30 + 60)->toDateTimeString());
});

it('restarts the clock from an owner sign-in on day 59 and schedules nothing before day 105', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $workspace->owner->forceFill(['last_login_at' => inactivePausedAt()->addDays(59)])->save();

    $this->travelTo(inactivePausedAt()->addDays(60));
    runInactivityCommand();

    $this->travelTo(inactivityRunOn(59 + 45));
    runInactivityCommand();

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();
    Mail::assertNothingQueued();

    $this->travelTo(inactivityRunOn(59 + 46));
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

it('does not schedule a workspace someone signs in to after it was read', function (string $who): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $member = User::factory()->create(['last_login_at' => null]);
    $workspace->users()->attach($member, ['role' => 'admin']);
    $signer = $who === 'owner' ? $workspace->owner : $member;

    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced, $signer): void {
        if ($raced || ! str_contains($query->sql, 'max("last_login_at")')) {
            return;
        }

        $raced = true;
        DB::table('users')->where('id', $signer->getKey())->update(['last_login_at' => now()]);
    });

    $this->travelTo(inactivePausedAt()->addDays(46));
    runInactivityCommand();

    expect($raced)->toBeTrue()
        ->and($workspace->refresh()->scheduled_deletion_at)->toBeNull()
        ->and($workspace->deletion_reason)->toBeNull();
    Mail::assertNothingQueued();
})->with(['owner', 'member']);

it('runs daily at 02:00 without overlapping itself', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();

    $events = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'billing:schedule-inactive-deletions'))
        ->values();

    expect($events->map(fn (Event $event): string => $event->expression)->all())->toBe(['0 2 * * *'])
        ->and($events->map(fn (Event $event): bool => $event->withoutOverlapping)->all())->toBe([true]);
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
    $workspace = inactivePausedWorkspace(timezone: 'Pacific/Kiritimati');
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

function inactivityCountdownOn(Workspace $workspace, DeletionReason $reason = DeletionReason::Inactivity): CarbonImmutable
{
    $deletesAt = now()->addDays(14)->startOfSecond();
    $workspace->forceFill(['scheduled_deletion_at' => $deletesAt, 'deletion_reason' => $reason])->save();

    return $deletesAt;
}

it('cancels the countdown when the owner signs in', function (): void {
    $workspace = inactivePausedWorkspace();
    inactivityCountdownOn($workspace);

    Auth::guard('web')->login($workspace->owner);

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull()
        ->and($workspace->deletion_reason)->toBeNull();
});

it('cancels the countdown when a member who is not the owner signs in', function (): void {
    $workspace = inactivePausedWorkspace();
    inactivityCountdownOn($workspace);
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'member']);

    Auth::guard('web')->login($member);

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull()
        ->and($workspace->deletion_reason)->toBeNull();
});

it('cancels every countdown the user belongs to', function (): void {
    $owned = inactivePausedWorkspace();
    $joined = inactivePausedWorkspace();
    inactivityCountdownOn($owned);
    inactivityCountdownOn($joined);
    $joined->users()->attach($owned->owner, ['role' => 'admin']);

    event(new Login('web', $owned->owner, false));

    expect($owned->refresh()->scheduled_deletion_at)->toBeNull()
        ->and($joined->refresh()->scheduled_deletion_at)->toBeNull();
});

it('leaves the countdown of a workspace the user does not belong to', function (): void {
    $workspace = inactivePausedWorkspace();
    $deletesAt = inactivityCountdownOn($workspace);

    Auth::guard('web')->login(User::factory()->create());

    expect($workspace->refresh()->scheduled_deletion_at?->equalTo($deletesAt))->toBeTrue()
        ->and($workspace->deletion_reason)->toBe(DeletionReason::Inactivity);
});

it('keeps an owner-requested deletion when the owner or a member signs in', function (): void {
    $workspace = inactivePausedWorkspace();
    $deletesAt = inactivityCountdownOn($workspace, DeletionReason::Requested);
    $member = User::factory()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);

    Auth::guard('web')->login($workspace->owner);
    Auth::guard('web')->login($member);

    expect($workspace->refresh()->scheduled_deletion_at?->equalTo($deletesAt))->toBeTrue()
        ->and($workspace->deletion_reason)->toBe(DeletionReason::Requested);
});

it('keeps a deletion that has no recorded reason when the owner signs in', function (): void {
    $workspace = inactivePausedWorkspace();
    $deletesAt = inactivityCountdownOn($workspace);
    DB::table('workspaces')->where('id', $workspace->getKey())->update(['deletion_reason' => null]);

    Auth::guard('web')->login($workspace->owner);

    expect($workspace->refresh()->scheduled_deletion_at?->equalTo($deletesAt))->toBeTrue();
});

it('does nothing for a workspace that has no countdown', function (): void {
    $workspace = inactivePausedWorkspace();

    resolve(CancelInactivityDeletion::class)->execute($workspace);

    expect($workspace->refresh()->scheduled_deletion_at)->toBeNull();
});

it('reminds the owner of an inactivity deletion five days before it', function (): void {
    Mail::fake();
    $this->travelTo(inactivePausedAt()->addDays(50)->setTime(2, 0));
    $workspace = inactivePausedWorkspace(['paused_at' => now()->subDays(55)]);
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(5)->setTime(17, 30), 'deletion_reason' => DeletionReason::Inactivity])->save();

    runInactivityCommand();

    Mail::assertQueued(InactiveWorkspaceDeletionReminderMail::class, 1);
    Mail::assertQueued(InactiveWorkspaceDeletionReminderMail::class, fn (InactiveWorkspaceDeletionReminderMail $mail): bool => $mail->hasTo($workspace->owner->email)
        && $mail->workspace->is($workspace));
});

it('sends no reminder outside the five-day window or for a deletion the owner requested', function (): void {
    Mail::fake();
    $this->travelTo(inactivePausedAt()->addDays(50)->setTime(2, 0));
    $sixDays = inactivePausedWorkspace();
    $sixDays->forceFill(['scheduled_deletion_at' => now()->addDays(6), 'deletion_reason' => DeletionReason::Inactivity, 'paused_at' => now()->addDays(6)->subDays(60)])->save();
    $fourDays = inactivePausedWorkspace();
    $fourDays->forceFill(['scheduled_deletion_at' => now()->addDays(4), 'deletion_reason' => DeletionReason::Inactivity, 'paused_at' => now()->addDays(4)->subDays(60)])->save();
    $requested = inactivePausedWorkspace();
    $requested->forceFill(['scheduled_deletion_at' => now()->addDays(5), 'deletion_reason' => DeletionReason::Requested])->save();

    runInactivityCommand();

    Mail::assertNothingQueued();
    expect($sixDays->refresh()->deletion_reason)->toBe(DeletionReason::Inactivity)
        ->and($fourDays->refresh()->deletion_reason)->toBe(DeletionReason::Inactivity);
});

it('writes the reminder with the deletion date in the owner timezone', function (): void {
    $workspace = inactivePausedWorkspace(timezone: 'Pacific/Kiritimati');
    $mail = new InactiveWorkspaceDeletionReminderMail($workspace, CarbonImmutable::parse('2026-12-31 23:00:00'));

    $mail->assertHasSubject('Acme will be deleted on Jan 1, 2027');
    $mail->assertSeeInHtml('We will delete Acme and every record in it on Jan 1, 2027. This cannot be undone.');
    $mail->assertSeeInHtml('Choose a plan or sign in before then to keep it.');
    $mail->assertSeeInHtml('This is the last reminder. Choose a plan or sign in to keep it.');
    $mail->assertSeeInHtml('Questions? Reply to this email.');
    $mail->assertSeeInText('Choose a plan: '.Billing::getUrl(panel: 'app', tenant: $workspace));
});

it('writes the deleted confirmation from the workspace name alone', function (): void {
    $mail = new InactiveWorkspaceDeletedMail('Acme');

    $mail->assertHasSubject('Acme has been deleted');
    $mail->assertSeeInHtml('Acme was paused for 60 days with no sign-ins, so we deleted it and its records.');
    $mail->assertSeeInHtml('You can sign in at any time and start a new workspace.');
    $mail->assertSeeInHtml('It was paused for 60 days with no sign-ins.');
    $mail->assertSeeInHtml('Questions? Reply to this email.');
});

function purgeDueDeletion(Workspace $workspace, DeletionReason $reason): void
{
    test()->travelTo(inactivePausedAt()->addDays(60)->addMinute());

    $workspace->forceFill(['scheduled_deletion_at' => now()->subMinute(), 'deletion_reason' => $reason])->save();

    test()->artisan('app:purge-scheduled-deletions')->assertSuccessful();
}

it('confirms an inactivity deletion to the owner after the purge, from plain values', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $ownerEmail = $workspace->owner->email;

    purgeDueDeletion($workspace, DeletionReason::Inactivity);

    expect(Workspace::query()->whereKey($workspace->getKey())->exists())->toBeFalse();
    Mail::assertQueued(InactiveWorkspaceDeletedMail::class, 1);
    Mail::assertQueued(InactiveWorkspaceDeletedMail::class, function (InactiveWorkspaceDeletedMail $mail) use ($ownerEmail): bool {
        expect(serialize($mail))->not->toContain(Workspace::class);

        return $mail->hasTo($ownerEmail) && $mail->workspaceName === 'Acme';
    });
});

it('sends no confirmation after a deletion the owner requested', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();

    purgeDueDeletion($workspace, DeletionReason::Requested);

    expect(Workspace::query()->whereKey($workspace->getKey())->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

it('lets the owner of a purged inactive workspace sign in and start a new workspace with a fresh trial', function (): void {
    withHostedBilling();
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $owner = $workspace->owner;

    purgeDueDeletion($workspace, DeletionReason::Inactivity);

    expect($owner->refresh()->ownedWorkspaces()->count())->toBe(0);

    Auth::guard('web')->login($owner);
    $this->actingAs($owner);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Acme Again'])
        ->call('register')
        ->assertHasNoFormErrors();

    $created = $owner->ownedWorkspaces()->sole();

    expect($created->name)->toBe('Acme Again')
        ->and($created->getKey())->not->toBe($workspace->getKey())
        ->and($created->plan)->toBe(Plan::Pro)
        ->and($created->onGenericTrial())->toBeTrue()
        ->and($created->trial_ends_at?->isFuture())->toBeTrue()
        ->and($created->paused_at)->toBeNull()
        ->and($created->scheduled_deletion_at)->toBeNull()
        ->and($created->deletion_reason)->toBeNull();
});

it('lists who would get the launch notice and writes nothing without force', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();

    $this->travelTo(inactivePausedAt());

    $this->artisan('billing:announce-inactivity-rule')
        ->expectsOutputToContain('Would notify the owner of Acme.')
        ->expectsOutputToContain('1 owner(s) would be notified')
        ->assertSuccessful();

    expect($workspace->refresh()->inactivity_notice_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

it('sends one launch notice per paused owner, dated 60 days from the last activity', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace(timezone: 'Pacific/Kiritimati');

    $this->travelTo(inactivePausedAt());
    $this->artisan('billing:announce-inactivity-rule', ['--force' => true])
        ->expectsOutputToContain('Notifying the owner of Acme...')
        ->assertSuccessful();

    expect($workspace->refresh()->inactivity_notice_sent_at?->toDateTimeString())->toBe(inactivePausedAt()->toDateTimeString());

    Mail::assertQueued(InactivityRuleNoticeMail::class, 1);
    Mail::assertQueued(InactivityRuleNoticeMail::class, function (InactivityRuleNoticeMail $mail) use ($workspace): bool {
        $mail->assertHasSubject('We now keep paused workspaces for 60 days');
        $mail->assertSeeInText('We now keep paused workspaces for 60 days');
        $mail->assertSeeInText('Acme has been paused since its trial ended. From today, we delete a paused workspace after 60 days without a sign-in.');
        $mail->assertSeeInText('Your 60 days start today. If no one signs in and you choose no plan, we will delete Acme on Jan 1, 2027.');
        $mail->assertSeeInText('We will email you 14 days and 5 days before that.');
        $mail->assertSeeInText('Choose a plan: '.Billing::getUrl(panel: 'app', tenant: $workspace));

        return $mail->hasTo($workspace->owner->email) && $mail->workspace->is($workspace);
    });
});

it('dates the launch notice from a member sign-in ten days after the pause', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $member = User::factory()->create(['last_login_at' => inactivePausedAt()->addDays(10)]);
    $workspace->users()->attach($member, ['role' => 'admin']);

    $this->travelTo(inactivePausedAt()->addDays(11));
    $this->artisan('billing:announce-inactivity-rule', ['--force' => true])->assertSuccessful();

    Mail::assertQueued(InactivityRuleNoticeMail::class, function (InactivityRuleNoticeMail $mail): bool {
        $mail->assertSeeInText('we will delete Acme on Jan 10, 2027.');

        return $mail->deletesAt->toDateTimeString() === inactivePausedAt()->addDays(70)->toDateTimeString();
    });
});

it('never sends the launch notice twice', function (): void {
    Mail::fake();
    inactivePausedWorkspace();

    $this->travelTo(inactivePausedAt());
    $this->artisan('billing:announce-inactivity-rule', ['--force' => true])->assertSuccessful();
    $this->artisan('billing:announce-inactivity-rule', ['--force' => true])->assertSuccessful();

    Mail::assertQueued(InactivityRuleNoticeMail::class, 1);
});

it('skips a workspace already scheduled for deletion on request when announcing the rule', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace([
        'scheduled_deletion_at' => inactivePausedAt()->addDays(20),
        'deletion_reason' => DeletionReason::Requested,
    ]);

    $this->travelTo(inactivePausedAt());
    $this->artisan('billing:announce-inactivity-rule', ['--force' => true])->assertSuccessful();

    expect($workspace->refresh()->inactivity_notice_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

it('keeps the launch notice command out of the schedule', function (): void {
    $commands = collect(app(Schedule::class)->events())->map(fn (Event $event): string => (string) $event->command);

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'billing:schedule-inactive-deletions')))->toBeTrue()
        ->and($commands->contains(fn (string $command): bool => str_contains($command, 'billing:announce-inactivity-rule')))->toBeFalse();
});

it('never sends the launch notice to a trial paused after launch', function (): void {
    withHostedBilling();
    Mail::fake();
    $this->freezeTime();
    $workspace = inactivePausedWorkspace(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay(), 'paused_at' => null, 'inactivity_notice_sent_at' => null]);

    $this->artisan('billing:process-trials')->assertSuccessful();
    $this->artisan('billing:announce-inactivity-rule', ['--force' => true])->assertSuccessful();

    expect($workspace->refresh()->paused_at)->not->toBeNull()
        ->and($workspace->inactivity_notice_sent_at)->not->toBeNull();
    Mail::assertNotQueued(InactivityRuleNoticeMail::class);
});

it('does not send the launch notice to a workspace noticed after it was read', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $noticedAt = inactivePausedAt()->addHour();

    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced, $workspace, $noticedAt): void {
        if ($raced || ! str_contains($query->sql, 'max("last_login_at")')) {
            return;
        }

        $raced = true;
        DB::table('workspaces')->where('id', $workspace->getKey())->update(['inactivity_notice_sent_at' => $noticedAt]);
    });

    $this->travelTo(inactivePausedAt()->addDays(2));
    $this->artisan('billing:announce-inactivity-rule', ['--force' => true])
        ->expectsOutputToContain('Skipping Acme')
        ->assertSuccessful();

    expect($raced)->toBeTrue()
        ->and($workspace->refresh()->inactivity_notice_sent_at?->toDateTimeString())->toBe($noticedAt->toDateTimeString());
    Mail::assertNothingQueued();
});

it('does not send the launch notice to a workspace the owner asked to delete after it was read', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $requestedFor = inactivePausedAt()->addDays(30);

    $raced = false;
    DB::listen(function (QueryExecuted $query) use (&$raced, $workspace, $requestedFor): void {
        if ($raced || ! str_contains($query->sql, 'max("last_login_at")')) {
            return;
        }

        $raced = true;
        DB::table('workspaces')->where('id', $workspace->getKey())->update(['scheduled_deletion_at' => $requestedFor, 'deletion_reason' => DeletionReason::Requested->value]);
    });

    $this->travelTo(inactivePausedAt()->addDays(2));
    $this->artisan('billing:announce-inactivity-rule', ['--force' => true])
        ->expectsOutputToContain('Skipping Acme')
        ->assertSuccessful();

    expect($raced)->toBeTrue()
        ->and($workspace->refresh()->deletion_reason)->toBe(DeletionReason::Requested)
        ->and($workspace->scheduled_deletion_at?->toDateTimeString())->toBe($requestedFor->toDateTimeString())
        ->and($workspace->inactivity_notice_sent_at)->toBeNull();
    Mail::assertNothingQueued();
});

it('stores the deletion date the launch notice promised, whatever hour the workspace was paused', function (): void {
    Mail::fake();
    $workspaces = collect(['2026-11-01 00:30:00', '2026-11-01 09:59:00', INACTIVE_PAUSED_AT])
        ->map(fn (string $pausedAt): Workspace => inactivePausedWorkspace(['paused_at' => $pausedAt]));

    $this->travelTo(inactivePausedAt());
    $this->artisan('billing:announce-inactivity-rule', ['--force' => true])->assertSuccessful();

    foreach (range(1, 60) as $day) {
        $this->travelTo(inactivityRunOn($day));
        runInactivityCommand();
    }

    $inactive = resolve(InactiveTrialWorkspacesQuery::class);

    foreach ($workspaces as $workspace) {
        $promised = Mail::queued(InactivityRuleNoticeMail::class, fn (InactivityRuleNoticeMail $mail): bool => $mail->workspace->is($workspace))->sole()->deletesAt;

        expect($workspace->refresh()->scheduled_deletion_at?->toDateTimeString())->toBe($promised->toDateTimeString())
            ->and($inactive->stillDue($workspace))->toBeTrue();
    }
});

it('does not break sign-in when cancelling a countdown fails, and reports the failure', function (): void {
    Exceptions::fake();
    $workspace = inactivePausedWorkspace();
    inactivityCountdownOn($workspace);

    DB::listen(function (QueryExecuted $query): void {
        if (str_contains($query->sql, '"deletion_reason"')) {
            throw new RuntimeException('query failed');
        }
    });

    Auth::guard('web')->login($workspace->owner);

    expect(Auth::guard('web')->check())->toBeTrue();
    Exceptions::assertReported(RuntimeException::class);
});

it('sends no confirmation when an admin purges a workspace that was on an inactivity countdown', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    inactivityCountdownOn($workspace);

    $workspace->refresh()->queuePurge();

    expect(Workspace::query()->whereKey($workspace->getKey())->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

it('sends no confirmation when the owner is being deleted along with the workspace', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    inactivityCountdownOn($workspace);
    $workspace->update(['scheduled_deletion_at' => now()->subMinute()]);

    $workspace->owner->queuePurge();

    expect(Workspace::query()->whereKey($workspace->getKey())->exists())->toBeFalse();
    Mail::assertNothingQueued();
});

it('purges quietly when the owner row is already gone', function (): void {
    withHostedBilling();
    Mail::fake();
    Exceptions::fake();
    $workspace = inactivePausedWorkspace();
    DB::table('users')->where('id', $workspace->user_id)->delete();

    purgeDueDeletion($workspace, DeletionReason::Inactivity);

    expect(Workspace::query()->whereKey($workspace->getKey())->exists())->toBeFalse();
    Mail::assertNothingQueued();
    Exceptions::assertNothingReported();
});

/** @return array<string, Closure(Workspace): mixed> */
function deletionsThatNoLongerStand(): array
{
    $reasons = Arr::only(inactiveExclusions(), ['subscribed', 'grandfathered', 'charged once and since canceled', 'owed an unpaid managed invoice', 'bought a credit pack']);

    return [
        ...$reasons,
        'on an active managed plan' => function (Workspace $workspace): void {
            $workspace->forceFill(['plan' => Plan::Pro])->save();
            $workspace->subscriptions()->create(['type' => 'managed', 'stripe_id' => 'sub_managed_live', 'stripe_status' => 'active', 'stripe_price' => 'price_pro_monthly_test', 'quantity' => 1]);
        },
        'signed in to after the countdown began' => fn (Workspace $workspace): bool => $workspace->owner->forceFill(['last_login_at' => inactivePausedAt()->addDays(59)])->save(),
    ];
}

foreach (deletionsThatNoLongerStand() as $reason => $arrange) {
    it("keeps a workspace marked for inactivity deletion that is {$reason}", function () use ($arrange): void {
        withHostedBilling();
        Mail::fake();
        $workspace = inactivePausedWorkspace();
        $arrange($workspace);

        purgeDueDeletion($workspace, DeletionReason::Inactivity);

        $workspace->refresh();

        expect($workspace->scheduled_deletion_at)->toBeNull()
            ->and($workspace->deletion_reason)->toBeNull();
        Mail::assertNothingQueued();
    });
}

it('purges a workspace marked for inactivity deletion that is still eligible', function (): void {
    withHostedBilling();
    Mail::fake();
    $workspace = inactivePausedWorkspace();

    purgeDueDeletion($workspace, DeletionReason::Inactivity);

    expect(Workspace::query()->whereKey($workspace->getKey())->exists())->toBeFalse();
    Mail::assertQueued(InactiveWorkspaceDeletedMail::class, 1);
});

it('cancels a countdown that no longer stands instead of reminding the owner', function (): void {
    Mail::fake();
    $this->travelTo(inactivePausedAt()->addDays(50)->setTime(2, 0));
    $workspace = inactivePausedWorkspace(['paused_at' => now()->subDays(55), 'hosted_free_grandfathered_at' => now()->subYear()]);
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(5)->setTime(17, 30), 'deletion_reason' => DeletionReason::Inactivity])->save();

    runInactivityCommand();

    $workspace->refresh();

    expect($workspace->scheduled_deletion_at)->toBeNull()
        ->and($workspace->deletion_reason)->toBeNull();
    Mail::assertNothingQueued();
});

it('warns a late-found workspace when it is scheduled, reminds once nine days later and not again', function (): void {
    Mail::fake();
    $workspace = inactivePausedWorkspace();

    $this->travelTo(inactivePausedAt()->addDays(100)->setTime(2, 0));
    runInactivityCommand();

    Mail::assertQueued(InactiveWorkspaceDeletionWarningMail::class, 1);
    Mail::assertNotQueued(InactiveWorkspaceDeletionReminderMail::class);

    $this->travelTo(now()->addDays(8));
    runInactivityCommand();
    Mail::assertNotQueued(InactiveWorkspaceDeletionReminderMail::class);

    $this->travelTo(now()->addDay());
    runInactivityCommand();
    Mail::assertQueued(InactiveWorkspaceDeletionReminderMail::class, 1);

    $this->travelTo(now()->addDay());
    runInactivityCommand();
    Mail::assertQueued(InactiveWorkspaceDeletionReminderMail::class, 1);
    Mail::assertQueued(InactiveWorkspaceDeletionWarningMail::class, 1);
    expect($workspace->refresh()->deletion_reason)->toBe(DeletionReason::Inactivity);
});

it('sends a purged owner from the app panel to workspace setup, where a personal workspace starts', function (): void {
    withHostedBilling();
    Mail::fake();
    $workspace = inactivePausedWorkspace();
    $owner = $workspace->owner;

    purgeDueDeletion($workspace, DeletionReason::Inactivity);

    $this->actingAs($owner->refresh())
        ->get(Filament::getPanel('app')->getUrl())
        ->assertRedirect(Filament::getPanel('app')->getTenantRegistrationUrl());

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Acme Again'])
        ->call('register')
        ->assertHasNoFormErrors();

    expect($owner->ownedWorkspaces()->sole()->personal_workspace)->toBeTrue();
});
