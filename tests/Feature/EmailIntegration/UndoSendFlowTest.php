<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\EmailIntegration\Actions\CancelQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPriority;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(CancelQueuedEmailAction::class);

it('cancels a single send within the 30s undo window', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->for($user)->create([
        'team_id' => $user->currentTeam->getKey(),
    ]));

    $this->travelTo(now()->startOfSecond());
    $email = resolve(SendEmailAction::class)->execute([
        'connected_account_id' => $account->getKey(),
        'subject' => 'Hi',
        'body_html' => '<p>Hi</p>',
        'to' => [['email' => 'a@b.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'priority' => EmailPriority::PRIORITY,
        'scheduled_for' => null,
        'batch_id' => null,
        'in_reply_to_email_id' => null,
    ]);

    expect((int) round(abs($email->scheduled_for?->diffInSeconds(now()) ?? 0.0)))->toBe(30);

    resolve(CancelQueuedEmailAction::class)->execute($email->refresh());

    expect($email->refresh()->status)->toBe(EmailStatus::CANCELLED);
});

it('allows undo while SENDING when Gmail has not been called yet', function (): void {
    $email = ConnectedAccount::withoutEvents(fn (): Email => Email::factory()->create([
        'status' => EmailStatus::SENDING,
        'provider_message_id' => null,
    ]));

    resolve(CancelQueuedEmailAction::class)->execute($email);

    expect($email->refresh()->status)->toBe(EmailStatus::CANCELLED);
});

it('rejects undo once Gmail has accepted the message', function (): void {
    $email = ConnectedAccount::withoutEvents(fn (): Email => Email::factory()->create([
        'status' => EmailStatus::SENDING,
        'provider_message_id' => 'gmail-msg-id-123',
    ]));

    expect(fn () => resolve(CancelQueuedEmailAction::class)->execute($email))
        ->toThrow(RuntimeException::class);
});

it('rejects undo on terminal statuses', function (EmailStatus $status): void {
    $email = ConnectedAccount::withoutEvents(fn (): Email => Email::factory()->create([
        'status' => $status,
        'provider_message_id' => $status === EmailStatus::SENT ? 'gmail-msg-id-xyz' : null,
    ]));

    expect(fn () => resolve(CancelQueuedEmailAction::class)->execute($email))
        ->toThrow(RuntimeException::class);
})->with([
    EmailStatus::SENT,
    EmailStatus::CANCELLED,
    EmailStatus::FAILED,
]);
