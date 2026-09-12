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
        ->and(urldecode((string) ($query['scope'] ?? '')))->toContain('https://www.googleapis.com/auth/calendar.events')
        ->and(urldecode((string) ($query['scope'] ?? '')))->toContain('https://www.googleapis.com/auth/calendar.readonly')
        ->and($query['access_type'] ?? null)->toBe('offline')
        ->and($query['prompt'] ?? null)->toBe('consent');

    expect(session(RedirectController::WORKSPACE_SESSION_KEY))->toBe($user->currentTeam->getKey());
});

it('includes calendar.readonly even when the leftover capability query is sent', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $location = (string) $this->get(route('email-accounts.redirect', ['provider' => 'gmail']).'?capability=calendar')
        ->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(urldecode((string) ($query['scope'] ?? '')))
        ->toContain('https://www.googleapis.com/auth/calendar.events')
        ->toContain('https://www.googleapis.com/auth/calendar.readonly');
});

it('does not start Google consent when the user has no workspace', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('email-accounts.redirect', ['provider' => 'gmail']))
        ->assertRedirect('/')
        ->assertSessionHas('error', 'Select a team before connecting an account.');

    expect(session()->has(RedirectController::WORKSPACE_SESSION_KEY))->toBeFalse();
});
