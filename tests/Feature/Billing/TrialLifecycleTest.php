<?php

declare(strict_types=1);

use App\Actions\Billing\StartProTrial;
use App\Console\Commands\ProcessTrialsCommand;
use App\Enums\Plan;
use App\Mail\ProTrialEndingSoonMail;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Mail;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(StartProTrial::class);
mutates(ProcessTrialsCommand::class);

/** @return array{0: User, 1: Team} */
function trialOwnerAndTeam(): array
{
    $user = User::factory()->withPersonalTeam()->create();

    /** @var Team $team */
    $team = $user->currentTeam;

    return [$user, $team];
}

it('starts a pro trial for an eligible owner', function (): void {
    [$user, $team] = trialOwnerAndTeam();

    $started = app(StartProTrial::class)->execute($user, $team);

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($started)->toBeTrue()
        ->and($team->refresh()->plan)->toBe(Plan::Pro)
        ->and($team->trial_ends_at?->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($user->refresh()->pro_trial_used_at)->not->toBeNull()
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('does not start a second trial for the same user even on another team', function (): void {
    [$user, $team] = trialOwnerAndTeam();
    app(StartProTrial::class)->execute($user, $team);

    $otherTeam = Team::factory()->create(['user_id' => $user->getKey(), 'personal_team' => false]);

    expect(app(StartProTrial::class)->execute($user, $otherTeam->refresh()))->toBeFalse()
        ->and($otherTeam->refresh()->plan)->toBe(Plan::Free)
        ->and($otherTeam->trial_ends_at)->toBeNull();
});

it('refuses a trial to a non-owner', function (): void {
    [, $team] = trialOwnerAndTeam();
    $member = User::factory()->create();

    app(StartProTrial::class)->execute($member, $team);
})->throws(AuthorizationException::class);

it('does not start a trial when the team is not on Free', function (): void {
    [$user, $team] = trialOwnerAndTeam();
    $team->plan = Plan::Pro;
    $team->save();

    expect(app(StartProTrial::class)->execute($user, $team->refresh()))->toBeFalse();
});

it('does not start a trial when the team ever had a subscription', function (): void {
    [$user, $team] = trialOwnerAndTeam();
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_past',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
        'ends_at' => now()->subMonth(),
    ]);

    expect(app(StartProTrial::class)->execute($user, $team))->toBeFalse();
});

it('downgrades expired trials and resets the allowance', function (): void {
    [, $team] = trialOwnerAndTeam();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->sole();

    expect($team->refresh()->plan)->toBe(Plan::Free)
        ->and($team->trial_ends_at)->toBeNull()
        ->and($balance->credits_remaining)->toBe(Plan::Free->credits());
});

it('does not downgrade an expired trial that converted to a subscription', function (): void {
    [, $team] = trialOwnerAndTeam();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subDay()])->save();
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_live',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    $this->artisan('billing:process-trials')->assertSuccessful();

    expect($team->refresh()->plan)->toBe(Plan::Pro)
        ->and($team->trial_ends_at)->toBeNull();
});

it('emails the owner when the trial ends in three days', function (): void {
    $this->travelTo(now()->startOfDay()->addHours(12));
    Mail::fake();

    [, $team] = trialOwnerAndTeam();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(3)->addHour()])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertQueued(ProTrialEndingSoonMail::class, 1);
});

it('does not email when the trial is outside the three-day window', function (): void {
    Mail::fake();

    [, $team] = trialOwnerAndTeam();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(7)])->save();

    $this->artisan('billing:process-trials')->assertSuccessful();

    Mail::assertNothingQueued();
});
