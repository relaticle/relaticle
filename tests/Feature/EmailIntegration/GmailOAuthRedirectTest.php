<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Controllers\RedirectController;

mutates(RedirectController::class);

beforeEach(function (): void {
    config()->set('services.gmail.client_id', 'gmail-client-id');
    config()->set('services.gmail.client_secret', 'gmail-client-secret');
    config()->set('services.gmail.redirect', 'http://localhost/email-accounts/callback/gmail');
});

it('redirects to Google using the Gmail OAuth client and the email-account callback', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $location = (string) $this->get(route('email-accounts.redirect', ['provider' => 'gmail']))
        ->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toContain('accounts.google.com')
        ->and($query['client_id'] ?? null)->toBe('gmail-client-id')
        // The connect flow must return to the email-account callback, NOT the social-login one.
        ->and($query['redirect_uri'] ?? null)->toBe('http://localhost/email-accounts/callback/gmail')
        ->and(urldecode((string) ($query['scope'] ?? '')))->toContain('https://www.googleapis.com/auth/gmail.readonly')
        ->and(urldecode((string) ($query['scope'] ?? '')))->toContain('https://www.googleapis.com/auth/gmail.send')
        ->and($query['access_type'] ?? null)->toBe('offline')
        ->and($query['prompt'] ?? null)->toBe('consent');
});

it('adds the calendar.readonly scope when capability=calendar', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $location = (string) $this->get(route('email-accounts.redirect', ['provider' => 'gmail']).'?capability=calendar')
        ->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(urldecode((string) ($query['scope'] ?? '')))->toContain('https://www.googleapis.com/auth/calendar.readonly');
});
