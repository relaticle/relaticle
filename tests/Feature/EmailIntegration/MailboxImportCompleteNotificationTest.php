<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;

mutates(MailboxHistoryImportCompletedNotification::class);

it('renders the mailbox import complete mail with sync stats', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create(['name' => 'Asmit']);
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $user->currentWorkspace->getKey(),
        'email_address' => 'whitesharkdevs@gmail.com',
        'initial_sync_imported' => 142,
        'initial_calendar_sync_imported' => 6,
    ]));

    $mail = (new MailboxHistoryImportCompletedNotification($account))->toMail($user);
    $html = (string) $mail->render();

    expect($html)->toContain('whitesharkdevs@gmail.com')
        ->and($html)->toContain($user->currentWorkspace->name)
        ->and($html)->toContain('142')
        ->and($html)->toContain('6')
        ->and($html)->toContain(__('mail.mailbox_import_complete.cta'))
        ->and($html)->toContain('brand/email-logo-lockup.png')
        ->and($html)->toContain('brand/email-logo-lockup-dark.png');
});

it('queues the mailbox import complete notification', function (): void {
    Notification::fake();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create());

    $account->user?->notify(new MailboxHistoryImportCompletedNotification($account));

    Notification::assertSentTo(
        $account->user,
        MailboxHistoryImportCompletedNotification::class,
    );
});
