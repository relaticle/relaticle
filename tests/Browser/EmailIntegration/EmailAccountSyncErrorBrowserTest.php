<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\SyncErrorReason;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailAccountsPage::class, ConnectedAccount::class);

it('explains a mailbox whose google workspace has gmail switched off', function (string $theme): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'olivia@acme.example',
        'status' => EmailAccountStatus::ERROR,
        'last_error' => SyncErrorReason::GoogleMailDisabled->value,
    ]));

    $page = loginViaBrowser($user, $theme)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/workspace/email")
        ->waitForText($account->email_address)
        ->assertSee(__('filament/pages/email-accounts.sync_error.badge'))
        ->assertDontSee(SyncErrorReason::GoogleMailDisabled->value)
        ->hover('[aria-label="'.SyncErrorReason::GoogleMailDisabled->getLabel().'"]')
        ->waitForText(SyncErrorReason::GoogleMailDisabled->getLabel())
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: "email-account-sync-error-{$theme}");
})->with(['inLightMode', 'inDarkMode']);
