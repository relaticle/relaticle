<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Livewire\AccessRequestsTable;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

mutates(AccessRequestsTable::class);

it('opens a requested email in the reader from the access requests table', function (string $theme): void {
    $owner = User::factory()->withWorkspace()->create();
    $workspace = $owner->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $owner->id,
        'workspace_id' => $workspace->id,
        'sync_cursor' => 'history-done',
    ]));
    $email = Email::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->id,
        'subject' => 'Q3 pricing proposal for Globex',
    ]);
    $requester = User::factory()->create(['current_workspace_id' => $workspace->id]);
    $workspace->users()->attach($requester, ['role' => 'member']);
    EmailAccessRequest::factory()->pending()->create([
        'owner_id' => $owner->id,
        'requester_id' => $requester->id,
        'email_id' => $email->id,
    ]);

    $page = visit('/app/login')->{$theme}()
        ->type('[id="form.email"]', $owner->email)
        ->click('button[type="submit"]')
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]')
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/workspace/email/access-requests")
        ->assertMissing('.fi-email-reader-panel')
        ->click('button[wire\\:click*="openEmail"]')
        ->assertVisible('.fi-email-reader-panel')
        ->assertPathIs("/app/{$workspace->slug}/workspace/email/access-requests");

    $page->screenshot(filename: "access-request-open-email-{$theme}");

    $page->click('.fi-email-reader-panel button[x-on\\:click="closeReader()"]')
        ->assertMissing('.fi-email-reader-panel')
        ->click('button[wire\\:click*="openEmail"]')
        ->assertVisible('.fi-email-reader-panel')
        ->assertNoJavaScriptErrors();
})->with(['inLightMode', 'inDarkMode']);
