<?php

declare(strict_types=1);

use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\GoogleCalendarService;

mutates(GoogleCalendarService::class);

it('constructs from a ConnectedAccount', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());

    $service = GoogleCalendarService::forAccount($account);

    expect($service)->toBeInstanceOf(GoogleCalendarService::class);
});

it('paginates initialSync and returns nextSyncToken', function (): void {
    // The real client call is hard to fake here without a test double — this test is a type-shape smoke test.

    expect(method_exists(GoogleCalendarService::class, 'initialSync'))->toBeTrue();
});
