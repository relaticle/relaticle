<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

it('denies every user when no administrator emails are configured', function (): void {
    config()->set('relaticle.horizon.admin_emails', []);

    $user = User::factory()->create(['email' => 'someone@example.com']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse();
});

it('allows a user whose email is configured', function (): void {
    config()->set('relaticle.horizon.admin_emails', ['ops@self-hosted.test']);

    $user = User::factory()->create(['email' => 'ops@self-hosted.test']);

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

    $user = User::factory()->create(['email' => 'ops@self-hosted.test']);

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeTrue();
});
