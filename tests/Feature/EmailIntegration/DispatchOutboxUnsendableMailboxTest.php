<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Filament\Pages\Billing as BillingPage;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Actions\MarkEmailsSendFailedAction;
use Relaticle\EmailIntegration\Actions\SyncEmailBatchCountersAction;
use Relaticle\EmailIntegration\Console\Commands\DispatchOutboxCommand;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailBatchStatus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Enums\SendFailureRemedy;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\SendEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBatch;
use Relaticle\EmailIntegration\Notifications\EmailSendFailedNotification;

mutates(DispatchOutboxCommand::class, MarkEmailsSendFailedAction::class, SyncEmailBatchCountersAction::class, EmailSendFailedNotification::class, SendFailureRemedy::class, ConnectedAccount::class);

function presentedSendFailure(EmailSendFailedNotification $notification, User $user, Workspace $workspace): FilamentNotification
{
    $row = NotificationRow::factory()
        ->make(['notifiable_id' => $user->getKey(), 'data' => $notification->facts()])
        ->setRelation('workspace', $workspace);

    return EmailSendFailedNotification::present($row);
}

beforeEach(function (): void {
    Bus::fake();
    Notification::fake();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->currentWorkspace;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => EmailAccountStatus::REAUTH_REQUIRED,
    ]));

    $this->queue = fn (array $attributes = []): Email => Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::QUEUED,
        'scheduled_for' => null,
        ...$attributes,
    ]);
});

it('fails due queued mail on a mailbox that needs reconnecting and tells the sender once', function (): void {
    $first = ($this->queue)();
    $second = ($this->queue)();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($first->fresh())
        ->status->toBe(EmailStatus::FAILED)
        ->last_error->toBe(__('filament/notifications/email-send-failed.reasons.mailbox_needs_reconnect'))
        ->and($second->fresh()->status)->toBe(EmailStatus::FAILED);

    Bus::assertNotDispatched(SendEmailJob::class);
    Notification::assertSentToTimes($this->user, EmailSendFailedNotification::class, 1);
    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, function (EmailSendFailedNotification $notification): bool {
        $presented = presentedSendFailure($notification, $this->user, $this->workspace);

        return $notification->facts()['count'] === 2
            && $presented->getTitle() === '2 emails not sent'
            && $presented->getBody() === 'Their mailbox needs reconnecting. Reconnect it, then retry them from the Failed tab.'
            && $presented->getActions()[0]->getUrl() === EmailAccountsPage::getUrl(panel: 'app', tenant: $this->workspace);
    });
});

it('fails due queued mail on a disconnected mailbox', function (): void {
    $email = ($this->queue)();
    $this->account->update(['status' => EmailAccountStatus::ACTIVE]);
    $this->account->delete();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect(Email::withoutGlobalScopes()->findOrFail($email->getKey())->status)->toBe(EmailStatus::FAILED);
    Notification::assertSentToTimes($this->user, EmailSendFailedNotification::class, 1);
    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, fn (EmailSendFailedNotification $notification): bool => $notification->facts()['remedy'] === SendFailureRemedy::Reconnect->value);
});

it('fails due queued mail on an active mailbox that lost send access', function (): void {
    $this->account->update(['status' => EmailAccountStatus::ACTIVE, 'capabilities' => ['email' => true, 'send' => false]]);
    $email = ($this->queue)();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($email->fresh()->status)->toBe(EmailStatus::FAILED);
    Bus::assertNotDispatched(SendEmailJob::class);
});

it('fails mail stuck sending on a disconnected mailbox instead of sending it after reconnect', function (): void {
    $stuck = ($this->queue)(['status' => EmailStatus::SENDING, 'provider_message_id' => null]);
    Email::withoutGlobalScopes()->whereKey($stuck->getKey())->toBase()->update(['updated_at' => now()->subDay()]);
    $this->account->update(['status' => EmailAccountStatus::ACTIVE]);
    $this->account->delete();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();
    $this->account->restore();
    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($stuck->fresh()->status)->toBe(EmailStatus::FAILED);
    Bus::assertNotDispatched(SendEmailJob::class);
});

it('counts failed mass-send mail on a disconnected mailbox and tells the sender once', function (): void {
    $batch = EmailBatch::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
        'total_recipients' => 2,
    ]);
    ($this->queue)(['batch_id' => $batch->getKey()]);
    ($this->queue)(['batch_id' => $batch->getKey()]);
    $this->account->delete();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($batch->fresh())
        ->failed_count->toBe(2)
        ->status->toBe(EmailBatchStatus::PartialFailure);

    Notification::assertSentToTimes($this->user, EmailSendFailedNotification::class, 1);
    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, fn (EmailSendFailedNotification $notification): bool => $notification->facts()['count'] === 2
        && $notification->facts()['remedy'] === SendFailureRemedy::Reconnect->value);
});

it('leaves mail scheduled for later queued on a mailbox that needs reconnecting', function (): void {
    $later = ($this->queue)(['scheduled_for' => now()->addDay()]);

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($later->fresh()->status)->toBe(EmailStatus::QUEUED);
    Notification::assertNothingSent();
});

it('keeps sending due mail on an active mailbox', function (): void {
    $this->account->update(['status' => EmailAccountStatus::ACTIVE]);
    $email = ($this->queue)();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($email->fresh()->status)->not->toBe(EmailStatus::FAILED);
    Notification::assertNothingSent();
});

it('fails due mail in a paused workspace and sends the sender to billing', function (): void {
    withHostedBilling();
    $this->account->update(['status' => EmailAccountStatus::ACTIVE]);
    $due = ($this->queue)();
    $later = ($this->queue)(['scheduled_for' => now()->addDay()]);

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($due->fresh())
        ->status->toBe(EmailStatus::FAILED)
        ->last_error->toBe(__('filament/notifications/email-send-failed.reasons.workspace_paused'))
        ->and($later->fresh()->status)->toBe(EmailStatus::QUEUED);

    Bus::assertNotDispatched(SendEmailJob::class);
    Notification::assertSentTo($this->user, EmailSendFailedNotification::class, function (EmailSendFailedNotification $notification) use ($due): bool {
        $presented = presentedSendFailure($notification, $this->user, $this->workspace);
        $workspaceName = e($this->workspace->name);

        return $notification->facts()['remedy'] === SendFailureRemedy::Subscribe->value
            && $presented->getBody() === "\"{$due->subject}\" was not sent because {$workspaceName} is paused. Once the workspace is reopened, retry the email from the Failed tab."
            && $presented->getActions()[0]->getUrl() === BillingPage::getUrl(panel: 'app', tenant: $this->workspace);
    });
});

it('holds due mail in a paused workspace when failing it throws', function (): void {
    withHostedBilling();
    $this->account->update(['status' => EmailAccountStatus::ACTIVE]);
    $email = ($this->queue)();
    Email::updating(fn (): never => throw new RuntimeException('database unavailable'));

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($email->fresh()->status)->toBe(EmailStatus::QUEUED);
    Bus::assertNotDispatched(SendEmailJob::class);
});

it('sends due mail in a workspace whose trial is running', function (): void {
    withHostedBilling();
    $this->workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save();
    $this->account->update(['status' => EmailAccountStatus::ACTIVE]);
    $email = ($this->queue)();

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($email->fresh()->status)->toBe(EmailStatus::SENDING);
    Bus::assertDispatched(SendEmailJob::class);
    Notification::assertNothingSent();
});
