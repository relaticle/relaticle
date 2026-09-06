<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;

mutates(MailboxHistoryImportCompletedNotification::class);

it('notifies that import is complete without a view action', function (): void {
    $user = User::factory()->withTeam()->create();
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->id,
        'team_id' => $user->current_team_id,
        'email_address' => 'sales@example.com',
        'initial_sync_imported' => 42,
    ]));

    $notification = new MailboxHistoryImportCompletedNotification($account);
    $payload = $notification->toDatabase($user);
    $mail = $notification->toMail($user);

    expect($payload['title'])->toBe('Mailbox import complete')
        ->and($payload['body'])->toBe('42 emails imported from sales@example.com.')
        ->and($payload['actions'] ?? [])->toBe([]);

    expect($mail->actionText)->toBeNull()
        ->and($mail->actionUrl)->toBeNull();
});
