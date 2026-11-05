<?php

declare(strict_types=1);

use App\Contracts\Entitlements;
use App\Enums\BillingStatus;
use App\Enums\DeletionReason;
use App\Enums\Plan;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Cloud\Billing\Actions\ExtendEndedTrial;
use Relaticle\Cloud\Billing\Actions\StartProTrial;
use Relaticle\Cloud\Billing\Commands\ProcessTrialsCommand;
use Relaticle\Cloud\Billing\Mail\ProEndedMail;
use Relaticle\Cloud\Billing\Mail\ProTrialEndingSoonMail;
use Spatie\Activitylog\Models\Activity;

mutates(StartProTrial::class);
mutates(ExtendEndedTrial::class);
mutates(ProcessTrialsCommand::class);
mutates(CreditService::class);

/** @return array{0: User, 1: Workspace} */
function trialOwnerAndWorkspace(): array
{
    $user = User::factory()->withPersonalWorkspace()->create();

    /** @var Workspace $workspace */
    $workspace = $user->currentWorkspace;

    return [$user, $workspace];
}

it('starts a pro trial for an eligible owner', function (): void {
    withHostedBilling();

    [$user, $workspace] = trialOwnerAndWorkspace();

    $started = app(StartProTrial::class)->execute($user, $workspace);

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($started)->toBeTrue()
        ->and($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and($workspace->trial_ends_at?->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($workspace->pro_trial_used_at)->not->toBeNull()
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('starts a separate trial for each workspace the user creates', function (): void {
    [$user, $workspace] = trialOwnerAndWorkspace();
    app(StartProTrial::class)->execute($user, $workspace);

    $otherWorkspace = Workspace::factory()->create(['user_id' => $user->getKey(), 'personal_workspace' => false]);

    expect(app(StartProTrial::class)->execute($user, $otherWorkspace->refresh()))->toBeTrue()
        ->and($otherWorkspace->refresh()->plan)->toBe(Plan::Pro)
        ->and($otherWorkspace->trial_ends_at?->isSameDay(now()->addDays(14)))->toBeTrue();
});

it('does not start a second trial on a workspace that already used one', function (): void {
    [$user, $workspace] = trialOwnerAndWorkspace();
    app(StartProTrial::class)->execute($user, $workspace);

    $workspace->forceFill(['plan' => Plan::Free, 'trial_ends_at' => null])->save();

    expect(app(StartProTrial::class)->execute($user, $workspace->refresh()))->toBeFalse()
        ->and($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and($workspace->trial_ends_at)->toBeNull();
});

it('refuses a trial to a non-owner', function (): void {
    [, $workspace] = trialOwnerAndWorkspace();
    $member = User::factory()->create();

    app(StartProTrial::class)->execute($member, $workspace);
})->throws(AuthorizationException::class);

it('does not start a trial when the workspace is not on Free', function (): void {
    [$user, $workspace] = trialOwnerAndWorkspace();
    $workspace->plan = Plan::Pro;
    $workspace->save();

    expect(app(StartProTrial::class)->execute($user, $workspace->refresh()))->toBeFalse();
});

it('does not start a trial when the workspace ever had a subscription', function (): void {
    [$user, $workspace] = trialOwnerAndWorkspace();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_past',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subMonth(),
    ]);

    expect(app(StartProTrial::class)->execute($user, $workspace))->toBeFalse();
});

it('downgrades expired trials and resets the allowance', function (): void {
    withHostedBilling();
    $this->freezeTime();

    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and($workspace->trial_ends_at)->toBeNull()
        ->and($workspace->paused_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('does not downgrade an expired trial that converted to a subscription', function (): void {
    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay()])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_live',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    $this->artisan('billing:process-trials')->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and($workspace->trial_ends_at)->toBeNull()
        ->and($workspace->paused_at)->toBeNull();
});

it('downgrades an expired trial whose only subscription never started', function (string $status): void {
    Mail::fake();

    [$owner, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill([
        'plan' => Plan::Pro,
        'trial_ends_at' => now()->subHour(),
        'pro_trial_used_at' => now()->subDays(14),
    ])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_unstarted',
        'stripe_status' => $status,
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => null,
    ]);

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(ProEndedMail::class, fn (ProEndedMail $mail): bool => $mail->hasTo($owner->email)
        && $mail->status === BillingStatus::TrialEnded);

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and($workspace->trial_ends_at)->toBeNull()
        ->and($workspace->billingStatus())->toBe(BillingStatus::TrialEnded);
})->with(['incomplete', 'incomplete_expired']);

it('emails the owner once when their expired trial is paused', function (): void {
    Mail::fake();

    [$owner, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subHour()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();
    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(ProEndedMail::class, 1);
    Mail::assertQueued(ProEndedMail::class, fn (ProEndedMail $mail): bool => $mail->hasTo($owner->email)
        && $mail->status === BillingStatus::TrialEnded
        && $mail->workspace->is($workspace));
});

it('still reads Trial ended after the downgrade pauses the trial', function (): void {
    Mail::fake();

    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill([
        'plan' => Plan::Pro,
        'trial_ends_at' => now()->subHour(),
        'pro_trial_used_at' => now()->subDays(14),
    ])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    expect($workspace->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded);
});

it('sends no trial-ended email when the plan outlives the trial', function (callable $arrange): void {
    Mail::fake();

    [, $workspace] = trialOwnerAndWorkspace();
    $arrange($workspace);

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertNotQueued(ProEndedMail::class);
})->with([
    'converted to a subscription' => [function (Workspace $workspace): void {
        $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay()])->save();
        $workspace->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_live',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro_monthly_test',
            'quantity' => 1,
        ]);
    }],
    'granted Enterprise by a sysadmin' => [fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Enterprise, 'trial_ends_at' => now()->subDay()])->save()],
]);

it('emails the owner when the trial ends within 72 hours', function (): void {
    Mail::fake();

    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addHours(71)])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(ProTrialEndingSoonMail::class, 1);
});

it('sends no trial reminder to a workspace scheduled for deletion', function (): void {
    Mail::fake();

    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill([
        'plan' => Plan::Pro,
        'trial_ends_at' => now()->addHours(71),
        'scheduled_deletion_at' => now()->addDays(30),
    ])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('pauses the expired trial of a workspace scheduled for deletion without emailing the owner', function (): void {
    Mail::fake();

    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill([
        'plan' => Plan::Pro,
        'trial_ends_at' => now()->subHour(),
        'scheduled_deletion_at' => now()->addDays(30),
    ])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertNothingQueued();

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and($workspace->trial_ends_at)->toBeNull();
});

it('processes trials every five minutes', function (): void {
    $this->artisan('schedule:list')->assertSuccessful();

    $expressions = collect(resolve(Schedule::class)->events())
        ->filter(fn (Event $event): bool => str_contains((string) $event->command, 'billing:process-trials'))
        ->map(fn (Event $event): string => $event->expression)
        ->values()
        ->all();

    expect($expressions)->toBe(['*/5 * * * *']);
});

it('reminds the owner once per trial end date across repeated runs', function (): void {
    Mail::fake();
    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addHours(71)])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();
    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(ProTrialEndingSoonMail::class, 1);
    expect($workspace->refresh()->trial_reminded_for?->equalTo($workspace->trial_ends_at))->toBeTrue();
});

it('reminds again when the trial end moves after a reminder', function (): void {
    Mail::fake();
    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addHours(71)])->save();
    $this->artisan('billing:process-trials')->assertSuccessful();

    $workspace->forceFill(['trial_ends_at' => now()->addHours(30)])->save();
    $this->artisan('billing:process-trials')->assertSuccessful();
    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(ProTrialEndingSoonMail::class, 2);
});

it('does not remind a trial more than 72 hours from its end', function (): void {
    Mail::fake();
    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addHours(73)])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertNotQueued(ProTrialEndingSoonMail::class);
});

it('downgrades an expired trial whose only subscription is an unpaid managed invoice', function (): void {
    Mail::fake();

    [$owner, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subHour()])->save();
    $workspace->subscriptions()->create([
        'type' => 'managed_pending',
        'stripe_id' => 'sub_managed_unpaid',
        'stripe_status' => 'active',
        'stripe_price' => 'price_inline_managed',
        'quantity' => 1,
    ]);

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(ProEndedMail::class, fn (ProEndedMail $mail): bool => $mail->hasTo($owner->email));

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and($workspace->trial_ends_at)->toBeNull();
});

it('keeps an expired trial on its plan when a paid managed subscription is live', function (): void {
    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subHour()])->save();
    $workspace->subscriptions()->create([
        'type' => 'managed',
        'stripe_id' => 'sub_managed_paid',
        'stripe_status' => 'active',
        'stripe_price' => 'price_inline_managed',
        'quantity' => 1,
    ]);

    $this->artisan('billing:process-trials')->assertSuccessful();

    expect($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and($workspace->trial_ends_at)->toBeNull();
});

it('reminds the owner when the only subscription never started', function (): void {
    $this->travelTo(today()->addHours(12));
    Mail::fake();

    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addHours(71)])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_unstarted',
        'stripe_status' => 'incomplete',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => null,
    ]);

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(ProTrialEndingSoonMail::class, 1);
});

it('skips ownerless workspaces without aborting trial reminders', function (): void {
    Mail::fake();

    [$deletedOwner, $ownerlessWorkspace] = trialOwnerAndWorkspace();
    $ownerlessWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addHours(71)])->save();
    $deletedOwner->delete();

    [, $ownedWorkspace] = trialOwnerAndWorkspace();
    $ownedWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addHours(71)])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(
        ProTrialEndingSoonMail::class,
        fn (ProTrialEndingSoonMail $mail): bool => $mail->workspace->is($ownedWorkspace),
    );
    Mail::assertQueuedCount(1);
});

it('does not email when the trial is outside the 72-hour window', function (): void {
    Mail::fake();

    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(7)])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('keeps a sysadmin-granted plan when a stale trial timestamp expires', function (): void {
    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Enterprise, 'trial_ends_at' => now()->subDay()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    $workspace->refresh();

    expect($workspace->plan)->toBe(Plan::Enterprise)
        ->and($workspace->trial_ends_at)->toBeNull();
});

it('grants exactly one allowance for a trial that crosses a month boundary', function (): void {
    withHostedBilling();

    $this->travelTo(new DateTimeImmutable('2026-06-25 12:00:00', new DateTimeZone('UTC')));

    [$user, $workspace] = trialOwnerAndWorkspace();
    app(StartProTrial::class)->execute($user, $workspace);

    $this->travelTo(new DateTimeImmutable('2026-07-02 12:00:00', new DateTimeZone('UTC')));
    $this->artisan('chat:reset-credits')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();
    expect($balance->period_ends_at->toDateTimeString())->toBe('2026-07-09 12:00:00');

    $grants = AiCreditTransaction::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('metadata->action', 'reset_period')
        ->count();
    expect($grants)->toBe(1);
});

it('grants no second pro allowance when the hourly reset runs before the downgrade', function (): void {
    withHostedBilling();

    $this->travelTo(new DateTimeImmutable('2026-06-10 03:00:00', new DateTimeZone('UTC')));

    [$user, $workspace] = trialOwnerAndWorkspace();
    app(StartProTrial::class)->execute($user, $workspace);

    $this->travelTo(new DateTimeImmutable('2026-06-24 04:00:00', new DateTimeZone('UTC')));
    $this->artisan('chat:reset-credits')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($workspace->refresh()->plan)->toBe(Plan::Pro)
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('keeps the pro allowance for an expired trial that converted to a subscription', function (): void {
    withHostedBilling();

    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subHour()])->save();
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_live',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    AiCreditBalance::query()
        ->where('workspace_id', $workspace->getKey())
        ->update(['period_ends_at' => now()->subHour()]);

    $this->artisan('chat:reset-credits')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($balance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('downgrades two or more expired trials in a single chunked run', function (): void {
    withHostedBilling();

    // chunkById() hydrates both workspaces in one query, which is what arms Eloquent's
    // strict lazy-loading guard (a single find() never does) -- a one-workspace test
    // cannot catch a resolver call that lazy loads the subscriptions relation.
    [, $workspaceA] = trialOwnerAndWorkspace();
    $workspaceA->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay()])->save();

    [, $workspaceB] = trialOwnerAndWorkspace();
    $workspaceB->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    $balanceA = AiCreditBalance::query()->where('workspace_id', $workspaceA->getKey())->sole();
    $balanceB = AiCreditBalance::query()->where('workspace_id', $workspaceB->getKey())->sole();

    expect($workspaceA->refresh()->plan)->toBe(Plan::Free)
        ->and($workspaceA->trial_ends_at)->toBeNull()
        ->and($balanceA->credits_remaining)->toBe(Plan::Free->credits())
        ->and($workspaceB->refresh()->plan)->toBe(Plan::Free)
        ->and($workspaceB->trial_ends_at)->toBeNull()
        ->and($balanceB->credits_remaining)->toBe(Plan::Free->credits());
});

function pauseTrialOf(Workspace $workspace): void
{
    $workspace->forceFill([
        'plan' => Plan::Free,
        'trial_ends_at' => null,
        'pro_trial_used_at' => now()->subDays(20),
    ])->save();
}

it('extends an ended trial by seven days once', function (): void {
    $this->travelTo(now()->startOfSecond());
    [$owner, $workspace] = trialOwnerAndWorkspace();
    pauseTrialOf($workspace);

    $first = app(ExtendEndedTrial::class)->execute($owner, $workspace);
    $endsAt = $workspace->trial_ends_at;
    $second = app(ExtendEndedTrial::class)->execute($owner, $workspace);

    expect($first)->toBeTrue()
        ->and($workspace->plan)->toBe(Plan::Pro)
        ->and($endsAt?->equalTo(now()->addDays(7)))->toBeTrue()
        ->and($workspace->trial_extended_at)->not->toBeNull()
        ->and($workspace->billingStatus())->toBe(BillingStatus::Trialing)
        ->and($second)->toBeFalse()
        ->and($workspace->refresh()->trial_ends_at?->equalTo($endsAt))->toBeTrue();
});

it('moves the trial end once when two stale copies of the workspace both extend', function (): void {
    $this->travelTo(now()->startOfSecond());
    [$owner, $workspace] = trialOwnerAndWorkspace();
    pauseTrialOf($workspace);
    $staleCopy = Workspace::query()->findOrFail($workspace->getKey());

    $results = [
        app(ExtendEndedTrial::class)->execute($owner, $workspace),
        app(ExtendEndedTrial::class)->execute($owner, $staleCopy),
    ];

    expect($results)->toBe([true, false])
        ->and($workspace->refresh()->trial_ends_at?->equalTo(now()->addDays(7)))->toBeTrue()
        ->and(Activity::query()->where('event', 'trial_self_extended')->count())->toBe(1);
});

it('re-reads the workspace under a row lock so concurrent extensions serialise', function (): void {
    [$owner, $workspace] = trialOwnerAndWorkspace();
    pauseTrialOf($workspace);

    $statements = [];
    DB::listen(function (QueryExecuted $query) use (&$statements): void {
        $statements[] = $query->sql;
    });

    app(ExtendEndedTrial::class)->execute($owner, $workspace);

    $locking = array_filter(
        $statements,
        fn (string $sql): bool => str_contains($sql, 'workspaces') && str_contains(mb_strtolower($sql), 'for update'),
    );

    expect($locking)->not->toBeEmpty();
});

it('refuses to extend until the pause has run, then extends and keeps the Free balance', function (): void {
    withHostedBilling();
    [$owner, $workspace] = trialOwnerAndWorkspace();
    app(StartProTrial::class)->execute($owner, $workspace);
    $this->travelTo(now()->addDays(14)->addMinute());

    $beforePause = app(ExtendEndedTrial::class)->execute($owner, $workspace->refresh());

    expect($beforePause)->toBeFalse()
        ->and($workspace->refresh()->trial_extended_at)->toBeNull();

    $this->artisan('billing:process-trials')->assertSuccessful();
    $afterPause = app(ExtendEndedTrial::class)->execute($owner, $workspace->refresh());
    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($afterPause)->toBeTrue()
        ->and($workspace->refresh()->trial_extended_at)->not->toBeNull()
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('refuses to extend a trial that is not ended', function (callable $arrange): void {
    [$owner, $workspace] = trialOwnerAndWorkspace();
    $arrange($workspace);
    $before = $workspace->refresh()->only(['plan', 'trial_ends_at', 'trial_extended_at']);

    $extended = app(ExtendEndedTrial::class)->execute($owner, $workspace);

    expect($extended)->toBeFalse()
        ->and($workspace->refresh()->only(['plan', 'trial_ends_at', 'trial_extended_at']))->toEqual($before);
})->with([
    'still running' => [fn (Workspace $workspace) => $workspace->forceFill([
        'plan' => Plan::Pro,
        'trial_ends_at' => now()->addDays(3),
        'pro_trial_used_at' => now()->subDays(11),
    ])->save()],
    'never trialled' => [fn (Workspace $workspace) => null],
    'ever had a charged subscription' => [function (Workspace $workspace): void {
        pauseTrialOf($workspace);
        $workspace->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_past',
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_pro_monthly_test',
            'quantity' => 1,
            'ends_at' => now()->subMonth(),
        ]);
    }],
    'scheduled for deletion' => [function (Workspace $workspace): void {
        pauseTrialOf($workspace);
        $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(30)])->save();
    }],
]);

it('lets the owner extend during an inactivity countdown and cancels it', function (): void {
    [$owner, $workspace] = trialOwnerAndWorkspace();
    pauseTrialOf($workspace);
    $workspace->forceFill(['scheduled_deletion_at' => now()->addDays(14), 'deletion_reason' => DeletionReason::Inactivity])->save();

    expect($workspace->canExtendTrial())->toBeTrue();

    $extended = app(ExtendEndedTrial::class)->execute($owner, $workspace);

    expect($extended)->toBeTrue()
        ->and($workspace->scheduled_deletion_at)->toBeNull()
        ->and($workspace->deletion_reason)->toBeNull()
        ->and($workspace->billingStatus())->toBe(BillingStatus::Trialing);
});

it('refuses to extend a deletion the owner requested and keeps it scheduled', function (): void {
    [$owner, $workspace] = trialOwnerAndWorkspace();
    pauseTrialOf($workspace);
    $deletesAt = now()->addDays(30)->startOfSecond();
    $workspace->forceFill(['scheduled_deletion_at' => $deletesAt, 'deletion_reason' => DeletionReason::Requested])->save();

    $extended = app(ExtendEndedTrial::class)->execute($owner, $workspace);

    expect($extended)->toBeFalse()
        ->and($workspace->refresh()->scheduled_deletion_at?->equalTo($deletesAt))->toBeTrue()
        ->and($workspace->deletion_reason)->toBe(DeletionReason::Requested)
        ->and($workspace->trial_extended_at)->toBeNull();
});

it('extends an ended trial whose only subscription never started', function (): void {
    [$owner, $workspace] = trialOwnerAndWorkspace();
    pauseTrialOf($workspace);
    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_unstarted',
        'stripe_status' => 'incomplete_expired',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    expect(app(ExtendEndedTrial::class)->execute($owner, $workspace))->toBeTrue();
});

it('refuses an extension from a member without billing rights', function (): void {
    [, $workspace] = trialOwnerAndWorkspace();
    pauseTrialOf($workspace);
    $admin = User::factory()->create();
    $workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    app(ExtendEndedTrial::class)->execute($admin, $workspace);
})->throws(AuthorizationException::class);

it('never raises the credit balance during an extension, even across a month boundary', function (): void {
    withHostedBilling();
    $this->travelTo(Date::parse('2026-06-28 09:00:00'));
    [$owner, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill([
        'plan' => Plan::Pro,
        'trial_ends_at' => now()->subHour(),
        'pro_trial_used_at' => now()->subDays(14),
    ])->save();
    $this->artisan('billing:process-trials')->assertSuccessful();
    $balance = fn (): int => AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole()->credits_remaining;
    $beforeExtending = $balance();

    app(ExtendEndedTrial::class)->execute($owner, $workspace);
    $this->travelTo(Date::parse('2026-07-02 09:00:00'));
    $this->artisan('chat:reset-credits')->assertSuccessful();

    expect($beforeExtending)->toBe(Plan::Free->credits())
        ->and($balance())->toBe($beforeExtending);

    $this->travelTo(Date::parse('2026-07-06 09:00:00'));
    $this->artisan('billing:process-trials')->assertSuccessful();

    expect($balance())->toBe(Plan::Free->credits());
});

it('records who extended the trial', function (): void {
    [$owner, $workspace] = trialOwnerAndWorkspace();
    pauseTrialOf($workspace);

    app(ExtendEndedTrial::class)->execute($owner, $workspace);

    $activity = Activity::query()->where('event', 'trial_self_extended')->sole();

    expect($activity->causer?->is($owner))->toBeTrue()
        ->and($activity->subject?->is($workspace))->toBeTrue()
        ->and($activity->properties['attributes']['trial_ends_at'])->toBe($workspace->trial_ends_at?->toDateTimeString());
});

it('subscribing during an extension restores the plan allowance', function (): void {
    withHostedBilling();
    [$owner, $workspace] = trialOwnerAndWorkspace();
    pauseTrialOf($workspace);
    app(ExtendEndedTrial::class)->execute($owner, $workspace);

    expect(resolve(Entitlements::class)->creditAllowance($workspace->refresh()))->toBe(Plan::Free->credits());

    $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_during_extension',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    expect(resolve(Entitlements::class)->creditAllowance($workspace->refresh()))->toBe(Plan::Pro->credits());
});
