<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Controllers\RedirectController;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;

mutates(RedirectController::class);

beforeEach(function (): void {
    config()->set('services.azure.client_id', 'azure-client-id');
    config()->set('services.azure.client_secret', 'azure-client-secret');
    config()->set('services.azure.redirect', 'http://localhost/email-accounts/callback/azure');
    config()->set('services.azure.tenant', 'common');
});

it('redirects to Microsoft with the least Graph scopes and prompt=consent', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $response = $this->get(MailboxOAuthWorkspace::redirectUrl('azure', $user->currentWorkspace));

    $location = $response->headers->get('Location');

    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($location)->toContain('login.microsoftonline.com')
        ->toContain('prompt=consent')
        ->and(explode(' ', (string) $query['scope']))->toEqualCanonicalizing([
            'https://graph.microsoft.com/Mail.Read',
            'https://graph.microsoft.com/Mail.Send',
            'https://graph.microsoft.com/User.Read',
            'offline_access',
            'https://graph.microsoft.com/Calendars.ReadWrite',
        ]);
});

it('includes Calendars.ReadWrite even when the leftover capability query is sent', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $response = $this->get(MailboxOAuthWorkspace::redirectUrl('azure', $user->currentWorkspace));

    expect($response->headers->get('Location'))
        ->toContain(urlencode('https://graph.microsoft.com/Calendars.ReadWrite'));
});
