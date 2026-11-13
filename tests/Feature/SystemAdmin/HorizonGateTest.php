<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Passkeys\Passkey;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\TrustPath\EmptyTrustPath;

function givePasskey(User $user): void
{
    Passkey::create([
        'user_id' => $user->getKey(),
        'name' => 'Chrome on macOS',
        'credential_id' => 'horizon-credential',
        'credential' => new CredentialRecord(
            'horizon-credential',
            'public-key',
            [],
            'none',
            new EmptyTrustPath,
            Uuid::fromString('00000000-0000-0000-0000-000000000000'),
            'public-key-bytes',
            'relaticle.test',
            0,
        ),
    ]);
}

it('denies every user when no administrator emails are configured', function (): void {
    config()->set('relaticle.horizon.admin_emails', []);

    $user = User::factory()->create(['email' => 'someone@example.com']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('allows a user whose email is configured', function (): void {
    config()->set('relaticle.horizon.admin_emails', ['ops@self-hosted.test']);

    $user = User::factory()->withConfirmedMfa()->create(['email' => 'ops@self-hosted.test']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
});

it('denies a user whose email is not configured', function (): void {
    config()->set('relaticle.horizon.admin_emails', ['ops@self-hosted.test']);

    $user = User::factory()->create(['email' => 'attacker@example.com']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('does not grant access to any hardcoded upstream address', function (): void {
    config()->set('relaticle.horizon.admin_emails', ['ops@self-hosted.test']);

    $user = User::factory()->create(['email' => 'manuk.minasyan1@gmail.com']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('matches configured emails case-insensitively', function (): void {
    config()->set('relaticle.horizon.admin_emails', ['Ops@Self-Hosted.test']);

    $user = User::factory()->withConfirmedMfa()->create(['email' => 'ops@self-hosted.test']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
});

it('denies a configured user whose email is not verified', function (): void {
    config()->set('relaticle.horizon.admin_emails', ['ops@self-hosted.test']);
    config()->set('app.require_email_verification', true);

    $user = User::factory()->unverified()->create(['email' => 'ops@self-hosted.test']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('allows a configured user whose email is verified', function (): void {
    config()->set('relaticle.horizon.admin_emails', ['ops@self-hosted.test']);
    config()->set('app.require_email_verification', true);

    $user = User::factory()->withConfirmedMfa()->create(['email' => 'ops@self-hosted.test', 'email_verified_at' => now()]);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
});

it('denies a configured verified user without a second factor', function (): void {
    config()->set('relaticle.horizon.admin_emails', ['ops@self-hosted.test']);

    $user = User::factory()->create(['email' => 'ops@self-hosted.test']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('allows a configured verified user who has a passkey', function (): void {
    config()->set('relaticle.horizon.admin_emails', ['ops@self-hosted.test']);

    $user = User::factory()->create(['email' => 'ops@self-hosted.test']);
    givePasskey($user);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
});

it('denies a configured user whose two-factor setup is unconfirmed and who has no passkey', function (): void {
    config()->set('relaticle.horizon.admin_emails', ['ops@self-hosted.test']);

    $user = User::factory()->withConfirmedMfa()->create([
        'email' => 'ops@self-hosted.test',
        'two_factor_confirmed_at' => null,
    ]);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});
