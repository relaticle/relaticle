<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Support\MailboxOAuthWorkspace;

it('includes calendar scope on the default mailbox connect redirect', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $this->actingAs($user);

    $response = $this->get(MailboxOAuthWorkspace::redirectUrl('gmail', $user->currentWorkspace));

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain(urlencode('https://www.googleapis.com/auth/calendar.events'))
        ->toContain(urlencode('https://www.googleapis.com/auth/calendar.readonly'));
});
