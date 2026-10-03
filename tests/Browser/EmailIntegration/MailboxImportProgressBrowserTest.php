<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Livewire\MeetingsHomeWidget;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailAccountsPage::class, MeetingsHomeWidget::class, ConnectedAccount::class);

it('shows the synced count without a percent while a mailbox is still being listed', function (string $theme): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'email_address' => 'olivia@acme.example',
        'sync_cursor' => null,
    ]));
    setHistoryImportBatchProgress(attachHistoryImportBatch($account), 1250, 40);

    $page = visit('/app/login')->{$theme}()
        ->type('[id="form.email"]', $user->email)
        ->click('button[type="submit"]')
        ->type('[id="form.password"]', 'password')
        ->click('button[type="submit"]')
        ->assertPathIs("/app/{$workspace->slug}")
        ->assertSee(__('filament/pages/dashboard.meetings.syncing.title'))
        ->assertSee(trans_choice('filament/pages/dashboard.meetings.syncing.emails_processed', 1210, ['count' => 1210]))
        ->assertDontSee('%')
        ->navigate("/app/{$workspace->slug}/workspace/email")
        ->waitForText($account->email_address)
        ->assertSee(__('filament/pages/email-accounts.importing'))
        ->assertSee(trans_choice('filament/pages/email-accounts.importing_count', 1210, ['count' => '1,210']))
        ->assertDontSee('%')
        ->assertNoJavaScriptErrors();

    $page->screenshot(filename: "mailbox-import-progress-{$theme}");
})->with(['inLightMode', 'inDarkMode']);
