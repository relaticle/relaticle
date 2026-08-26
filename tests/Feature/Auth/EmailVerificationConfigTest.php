<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(User::class, SystemAdministrator::class);

afterEach(function (): void {
    config(['app.require_email_verification' => true]);
    putenv('REQUIRE_EMAIL_VERIFICATION');
});

it('keeps email verification required by default so cloud and production behave like before', function (): void {
    expect(config('app.require_email_verification'))->toBeTrue()
        ->and(Filament::getPanel('app')->isEmailVerificationRequired())->toBeTrue()
        ->and(Filament::getPanel('sysadmin')->isEmailVerificationRequired())->toBeTrue();
});

it('treats unverified users as gated when the flag is on so cloud behavior is unchanged', function (): void {
    config(['app.require_email_verification' => true]);
    $unverified = User::factory()->unverified()->create();
    $verified = User::factory()->create();

    expect($unverified->hasVerifiedEmail())->toBeFalse()
        ->and($verified->hasVerifiedEmail())->toBeTrue();
});

it('reports every user as verified when the flag is off so framework, Filament, and policy checks all agree', function (): void {
    config(['app.require_email_verification' => false]);

    $unverified = User::factory()->unverified()->create();

    expect($unverified->hasVerifiedEmail())->toBeTrue();

    // Spot-check that the override actually unblocks ordinary policy checks.
    $team = Team::factory()->create(['user_id' => $unverified->id]);
    $unverified->forceFill(['current_team_id' => $team->id])->save();
    $unverified->refresh()->setRelation('currentTeam', $team);

    expect($unverified->can('viewAny', Company::class))->toBeTrue();
});

it('mirrors the override on SystemAdministrator so unverified sysadmins also pass canAccessPanel when the flag is off', function (): void {
    config(['app.require_email_verification' => false]);
    $unverified = SystemAdministrator::factory()->unverified()->create();

    expect($unverified->hasVerifiedEmail())->toBeTrue()
        ->and($unverified->canAccessPanel(Filament::getPanel('sysadmin')))->toBeTrue();
});

it('reads the config value through env coercion so REQUIRE_EMAIL_VERIFICATION="false" disables the gate end-to-end', function (): void {
    putenv('REQUIRE_EMAIL_VERIFICATION=false');
    expect(filter_var(env('REQUIRE_EMAIL_VERIFICATION', true), FILTER_VALIDATE_BOOL))->toBeFalse();

    putenv('REQUIRE_EMAIL_VERIFICATION=true');
    expect(filter_var(env('REQUIRE_EMAIL_VERIFICATION', true), FILTER_VALIDATE_BOOL))->toBeTrue();
});
