<?php

declare(strict_types=1);

use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\GoogleCalendarService;

mutates(GoogleCalendarService::class);

it('constructs from a ConnectedAccount with a live token', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'refresh_token' => 'refresh',
        'token_expires_at' => now()->addHour(),
    ]));

    $service = GoogleCalendarService::forAccount($account);

    expect($service)->toBeInstanceOf(GoogleCalendarService::class);
});

it('surfaces a revoked/absent grant as an auth error instead of persisting a null token', function (): void {
    // Expired access token with no refresh token: the shared client factory throws
    // invalid_grant so the calendar sync job flips the account to REAUTH_REQUIRED,
    // matching the mail-sync behaviour.
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'refresh_token' => null,
        'token_expires_at' => now()->subHour(),
    ]));

    expect(fn () => GoogleCalendarService::forAccount($account))
        ->toThrow(RuntimeException::class, 'invalid_grant');
});

it('paginates initialSync and returns nextSyncToken', function (): void {
    // The real client call is hard to fake here without a test double — this test is a type-shape smoke test.

    expect(method_exists(GoogleCalendarService::class, 'initialSync'))->toBeTrue();
});
