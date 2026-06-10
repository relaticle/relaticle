<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Relaticle\EmailIntegration\Actions\DisconnectConnectedAccountAction;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

/*
 * Reproduces the production bug:
 *   1. User connects a Gmail account.
 *   2. User disconnects it (soft delete — row stays with deleted_at set).
 *   3. User connects the SAME account again.
 *
 * The unique index (user_id, provider, email_address) spans soft-deleted rows,
 * so the second connect used to hit a 23505 unique violation. Reconnect must
 * instead restore the existing row.
 */

/**
 * Drive the OAuth callback the way the browser does after Google redirects back.
 */
function fakeGmailCallback(string $email, string $name): void
{
    $social = new SocialiteUser;
    $social->id = 'google-sub-123';
    $social->email = $email;
    $social->name = $name;
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = ['https://www.googleapis.com/auth/gmail.readonly'];

    Socialite::fake('google', $social);

    test()->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect();
}

it('restores a previously disconnected account instead of failing on the unique index', function (): void {
    Bus::fake();

    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    // 1. First connect.
    fakeGmailCallback('demo@example.com', 'Demo');

    $account = ConnectedAccount::query()
        ->where('email_address', 'demo@example.com')
        ->where('provider', EmailProvider::GMAIL)
        ->sole();

    expect($account->trashed())->toBeFalse();

    // 2. User disconnects — soft delete.
    resolve(DisconnectConnectedAccountAction::class)->execute($account);

    expect($account->fresh())->toBeNull(); // gone from the default scope
    expect(ConnectedAccount::withTrashed()->whereKey($account->getKey())->sole()->trashed())->toBeTrue();

    // 3. Reconnect the SAME account — must not throw a unique violation.
    fakeGmailCallback('demo@example.com', 'Demo Renamed');

    // Exactly one row total (the original was restored, not duplicated).
    expect(ConnectedAccount::withTrashed()->where('email_address', 'demo@example.com')->count())->toBe(1);

    $restored = ConnectedAccount::query()
        ->where('email_address', 'demo@example.com')
        ->sole();

    expect($restored->getKey())->toBe($account->getKey())    // same row reused
        ->and($restored->trashed())->toBeFalse()             // un-deleted
        ->and($restored->status->value)->toBe('active')
        ->and($restored->display_name)->toBe('Demo Renamed'); // fresh OAuth data applied
});
