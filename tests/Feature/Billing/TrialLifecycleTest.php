<?php

declare(strict_types=1);

use App\Actions\Billing\StartProTrial;
use App\Console\Commands\ProcessTrialsCommand;
use App\Enums\Plan;
use App\Mail\ProTrialEndingSoonMail;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;

mutates(StartProTrial::class);
mutates(ProcessTrialsCommand::class);

/** @return array{0: User, 1: Workspace} */
function trialOwnerAndWorkspace(): array
{
    $user = User::factory()->withPersonalWorkspace()->create();

    /** @var Workspace $workspace */
    $workspace = $user->currentWorkspace;

    return [$user, $workspace];
}

it('starts a pro trial for an eligible owner', function (): void {
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
    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($workspace->refresh()->plan)->toBe(Plan::Free)
        ->and($workspace->trial_ends_at)->toBeNull()
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
        ->and($workspace->trial_ends_at)->toBeNull();
});

it('emails the owner when the trial ends in three days', function (): void {
    $this->travelTo(now()->startOfDay()->addHours(12));
    Mail::fake();

    [, $workspace] = trialOwnerAndWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(3)->addHour()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(ProTrialEndingSoonMail::class, 1);
});

it('skips ownerless workspaces without aborting trial reminders', function (): void {
    $this->travelTo(now()->startOfDay()->addHours(12));
    Mail::fake();

    [$deletedOwner, $ownerlessWorkspace] = trialOwnerAndWorkspace();
    $ownerlessWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(3)->addHour()])->save();
    $deletedOwner->delete();

    [, $ownedWorkspace] = trialOwnerAndWorkspace();
    $ownedWorkspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(3)->addHour()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(
        ProTrialEndingSoonMail::class,
        fn (ProTrialEndingSoonMail $mail): bool => $mail->workspace->is($ownedWorkspace),
    );
    Mail::assertQueuedCount(1);
});

it('does not email when the trial is outside the three-day window', function (): void {
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

it('downgrades two or more expired trials in a single chunked run', function (): void {
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
