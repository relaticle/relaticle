<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Controllers\RedirectController;

mutates(RedirectController::class);

beforeEach(function (): void {
    config()->set('services.azure.client_id', 'azure-client-id');
    config()->set('services.azure.client_secret', 'azure-client-secret');
    config()->set('services.azure.redirect', 'http://localhost/email-accounts/callback/azure');
    config()->set('services.azure.tenant', 'common');
});

it('redirects to Microsoft with mail Graph scopes and prompt=consent', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $response = $this->get(route('email-accounts.redirect', ['provider' => 'azure']));

    $location = $response->headers->get('Location');

    expect($location)->toContain('login.microsoftonline.com')
        ->toContain('prompt=consent')
        ->toContain(urlencode('https://graph.microsoft.com/Mail.Read'))
        ->toContain(urlencode('https://graph.microsoft.com/Mail.Send'))
        ->toContain(urlencode('https://graph.microsoft.com/User.Read'))
        ->toContain(urlencode('offline_access'))
        ->not->toContain(urlencode('https://graph.microsoft.com/Calendars.Read'));
});

it('adds the Graph Calendars.Read scope when capability=calendar', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);

    $response = $this->get(route('email-accounts.redirect', ['provider' => 'azure']).'?capability=calendar');

    expect($response->headers->get('Location'))
        ->toContain(urlencode('https://graph.microsoft.com/Calendars.Read'));
});
