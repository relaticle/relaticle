<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationReason;
use App\Livewire\App\AppDatabaseNotifications;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\CancelQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Notifications\EmailSendHeldNotification;
use Relaticle\EmailIntegration\Support\QueuedSendNotifier;

mutates(EmailAccessNotificationHandler::class, EmailAccessRequestedNotification::class, EmailSendHeldNotification::class, QueuedSendNotifier::class, RequestEmailAccessAction::class, CancelQueuedEmailAction::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->actingAs($this->owner);
    $this->workspace = $this->owner->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->requester = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($this->requester, ['role' => 'member']);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->email = Email::factory()->private()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'subject' => 'Q4 pipeline review',
    ]);
});

describe('EmailAccessRequestedNotification', function (): void {
    it('presents a request notice as the requester and the subject, with no actions', function (): void {
        $this->requester->update(['name' => 'Dana Whitfield']);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        inboxAccessRequestNotice($this->owner, $request);
        $notification = $this->owner->unreadNotifications()->latest()->first();

        $payload = EmailAccessRequestedNotification::present($notification)->getDatabaseMessage();

        expect($payload['actions'] ?? [])->toBe([])
            ->and($payload['body'])->toBe('Q4 pipeline review')
            ->and($payload['title'])->toBe('Dana Whitfield requested email access');
    });

    it('shows an access request notice written before this change in the bell', function (): void {
        $this->requester->update(['name' => 'Dana Whitfield']);
        resolve(CurrentWorkspace::class)->set($this->workspace);
        inboxAccessRequestNotice($this->owner, EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]));

        Livewire::actingAs($this->owner)->test(AppDatabaseNotifications::class)
            ->assertSee('Dana Whitfield requested email access')
            ->assertSee('Q4 pipeline review');
    });

    it('is sent when a teammate requests access', function (): void {
        NotificationFacade::fake();

        resolve(RequestEmailAccessAction::class)->execute(
            $this->email,
            $this->requester,
            EmailPrivacyTier::FULL,
        );

        NotificationFacade::assertSentTo($this->owner, EmailAccessRequestedNotification::class);
    });
});

describe('EmailAccessNotificationHandler', function (): void {
    it('opens the emails-tab reader overlay from the notification event', function (): void {
        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertSet('selectedEmailId', $this->email->getKey())
            ->assertSee(__('filament/pages/email-inbox.reader.heading'))
            ->assertSee('Q4 pipeline review')
            ->assertSee('fi-email-reader-panel')
            ->assertDispatched('inbox:close');
    });

    it('opens the reader from the email query parameter for a viewer with full access', function (): void {
        Livewire::withQueryParams(['email' => $this->email->getKey()])
            ->test(EmailAccessNotificationHandler::class)
            ->assertSet('selectedEmailId', $this->email->getKey())
            ->assertSee('Q4 pipeline review');
    });

    it('opens nothing from the email query parameter for a member who cannot read the email', function (): void {
        $this->actingAs($this->requester);

        Livewire::withQueryParams(['email' => $this->email->getKey()])
            ->test(EmailAccessNotificationHandler::class)
            ->assertSet('selectedEmailId', null)
            ->assertDontSee('Q4 pipeline review');
    });

    it('opens nothing from the email query parameter for an outsider', function (): void {
        $outsider = User::factory()->withWorkspace()->create();
        $this->actingAs($outsider);

        Livewire::withQueryParams(['email' => $this->email->getKey()])
            ->test(EmailAccessNotificationHandler::class)
            ->assertSet('selectedEmailId', null)
            ->assertDontSee('Q4 pipeline review');
    });

    it('ignores an unknown email query parameter', function (): void {
        Livewire::withQueryParams(['email' => 'not-an-email'])
            ->test(EmailAccessNotificationHandler::class)
            ->assertSet('selectedEmailId', null);
    });

    it('shows approve and deny controls in the overlay for a pending request', function (): void {
        EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertSee($this->requester->name)
            ->assertSee(__('filament/pages/email-inbox.pending_access.approve'))
            ->assertSee(__('filament/pages/email-inbox.pending_access.deny'));
    });

    it('closes the overlay after approving from the reader bar', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertSee('fi-email-reader-panel')
            ->callAction(TestAction::make('approveAccessRequest')->arguments(['requestId' => $request->getKey()]))
            ->assertSet('selectedEmailId', null)
            ->assertDontSee('fi-email-reader-panel')
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified(__('filament/pages/email-access-requests.notifications.approved'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('closes the overlay after denying from the reader bar', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertSee('fi-email-reader-panel')
            ->callAction(TestAction::make('denyAccessRequest')->arguments(['requestId' => $request->getKey()]))
            ->assertSet('selectedEmailId', null)
            ->assertDontSee('fi-email-reader-panel')
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified(__('filament/pages/email-access-requests.notifications.denied'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('ignores approve for requests the viewer does not own', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        inboxAccessRequestNotice($this->owner, $request);

        $intruder = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
        $this->actingAs($intruder);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->callAction(TestAction::make('approveAccessRequest')->arguments(['requestId' => $request->getKey()]))
            ->assertNotNotified();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('ignores deny for requests the viewer does not own', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        inboxAccessRequestNotice($this->owner, $request);

        $intruder = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
        $this->actingAs($intruder);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->callAction(TestAction::make('denyAccessRequest')->arguments(['requestId' => $request->getKey()]))
            ->assertNotNotified();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('does not claim success when accepting a request that is no longer pending', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        inboxAccessRequestNotice($this->owner, $request);
        $request->update(['status' => EmailAccessRequestStatus::APPROVED]);
        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->callAction(TestAction::make('approveAccessRequest')->arguments(['requestId' => $request->getKey()]))
            ->assertSet('selectedEmailId', $this->email->getKey())
            ->assertNotNotified();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED)
            ->and($this->owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(1);
    });

    it('offers no overdue request to approve', function (): void {
        $request = EmailAccessRequest::factory()->overdue()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        NotificationFacade::fake();

        $component = Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->callAction(TestAction::make('approveAccessRequest')->arguments(['requestId' => $request->getKey()]))
            ->assertNotNotified();

        expect($component->instance()->pendingAccessRequests)->toHaveCount(0)
            ->and($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });

    it('dismisses the inline composer when selectedEmailId is cleared', function (): void {
        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertSet('selectedEmailId', $this->email->getKey())
            ->set('selectedEmailId', null)
            ->assertDispatched('composer:dismiss-inline');
    });
});

describe('undo queued send', function (): void {
    it('cancels the sender\'s queued email from the undo toast', function (): void {
        $email = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'status' => EmailStatus::QUEUED,
            'scheduled_for' => now()->addSeconds(5),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $email->getKey())
            ->assertNotified(__('filament/concerns/email-compose.notifications.cancelled.title'))
            ->assertDispatched('outbox:changed');

        expect($email->refresh()->status)->toBe(EmailStatus::CANCELLED);
    });

    it('does not cancel another user\'s queued email from the undo toast', function (): void {
        $teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
        $theirAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $teammate->id,
        ]));
        $theirs = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $teammate->id,
            'connected_account_id' => $theirAccount->getKey(),
            'status' => EmailStatus::QUEUED,
            'scheduled_for' => now()->addSeconds(5),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $theirs->getKey())
            ->assertNotified(__('filament/concerns/email-compose.notifications.not_found.title'));

        expect($theirs->refresh()->status)->toBe(EmailStatus::QUEUED);
    });

    it('says the email is already cancelled when undo is pressed twice', function (): void {
        $email = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'status' => EmailStatus::QUEUED,
            'scheduled_for' => now()->addSeconds(5),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $email->getKey())
            ->dispatch('undo-queued-send', emailId: (string) $email->getKey())
            ->assertNotified(__('filament/concerns/email-compose.notifications.cancelled.title'))
            ->assertNotNotified(__('filament/concerns/email-compose.notifications.too_late.title'));

        expect($email->refresh()->status)->toBe(EmailStatus::CANCELLED);
    });

    it('notifies too late when the queued email has already been sent', function (): void {
        $email = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'status' => EmailStatus::SENT,
            'sent_at' => now(),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $email->getKey())
            ->assertNotified(__('filament/concerns/email-compose.notifications.too_late.title'));

        expect($email->refresh()->status)->toBe(EmailStatus::SENT);
    });

    it('notifies too late when the queued email has already started sending', function (): void {
        $email = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'status' => EmailStatus::SENDING,
            'scheduled_for' => now()->subSecond(),
        ]);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $email->getKey())
            ->assertNotified(__('filament/concerns/email-compose.notifications.too_late.title'));

        expect($email->refresh()->status)->toBe(EmailStatus::SENDING);
    });
});

describe('EmailSendHeldNotification', function (): void {
    beforeEach(function (): void {
        $this->held = Email::factory()->outbound()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->owner->id,
            'connected_account_id' => $this->account->getKey(),
            'subject' => 'Next steps',
            'status' => EmailStatus::QUEUED,
            'scheduled_for' => now()->addSeconds(300),
        ]);
    });

    it('stores the held notice in the email\'s workspace', function (): void {
        $elsewhere = Workspace::factory()->create();
        $elsewhere->users()->attach($this->owner, ['role' => 'member']);
        $held = Email::factory()->outbound()->create([
            'workspace_id' => $elsewhere->id,
            'user_id' => $this->owner->id,
            'subject' => 'Next steps',
            'status' => EmailStatus::QUEUED,
            'scheduled_for' => now()->addSeconds(300),
        ]);

        resolve(QueuedSendNotifier::class)->sendHeld($held, $this->owner, 'Claude', 300);

        $row = $this->owner->notifications()->sole();

        expect($this->owner->current_workspace_id)->toBe($this->workspace->getKey())
            ->and($row->type)->toBe(EmailSendHeldNotification::class)
            ->and($row->workspace_id)->toBe($elsewhere->getKey())
            ->and($row->subject_type)->toBe('email')
            ->and($row->subject_id)->toBe($held->getKey())
            ->and($row->reason)->toBe(NotificationReason::System)
            ->and($row->group_key)->toBe("send-held:{$held->getKey()}")
            ->and($row->data['minutes'])->toBe(5)
            ->and($row->data['via'])->toBe('Claude');
    });

    it('takes the deadline from the time the email is scheduled for', function (): void {
        $this->held->update(['scheduled_for' => now()->addSeconds(200)]);

        resolve(QueuedSendNotifier::class)->sendHeld($this->held->fresh(), $this->owner, 'Claude', 300);

        expect($this->owner->notifications()->sole()->data['deadline'])->toBe($this->held->fresh()->scheduled_for->toIso8601ZuluString());
    });

    it('offers cancel while the hold lasts and stops once the email is sent', function (): void {
        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, 'Claude', 300);

        $held = EmailSendHeldNotification::present($this->owner->notifications()->sole());

        expect($held->getActions())->toHaveCount(1)
            ->and($held->getActions()[0]->getName())->toBe('cancelSend')
            ->and($held->getBody())->toContain('goes to');

        $this->held->update(['status' => EmailStatus::SENT]);
        $this->travelTo(now()->addSeconds(301));

        $sent = EmailSendHeldNotification::present($this->owner->notifications()->sole());

        expect($sent->getActions())->toBe([])
            ->and($sent->getBody())->toBe(__('filament/concerns/email-compose.notifications.held.sent_body', [
                'subject' => 'Next steps',
                'recipients' => '',
                'workspace' => e($this->workspace->name),
            ]));
    });

    it('keeps offering cancel for a held email still waiting to send after its hold', function (): void {
        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, 'Claude', 300);

        $this->travelTo(now()->addSeconds(301));

        $waiting = EmailSendHeldNotification::present($this->owner->notifications()->sole());

        expect($waiting->getActions())->toHaveCount(1)
            ->and($waiting->getActions()[0]->getName())->toBe('cancelSend')
            ->and($waiting->getBody())->toBe('"Next steps" to  is waiting to send. Workspace: '.e($this->workspace->name).'. Cancel it if you did not ask for it.');
    });

    it('says a cancelled held email was cancelled, also after the hold has passed', function (): void {
        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, 'Claude', 300);

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('undo-queued-send', emailId: (string) $this->held->getKey());

        $this->travelTo(now()->addSeconds(301));

        $notice = EmailSendHeldNotification::present($this->owner->notifications()->sole());

        expect($this->held->fresh()->status)->toBe(EmailStatus::CANCELLED)
            ->and($notice->getActions())->toBe([])
            ->and($notice->getBody())->toBe(__('filament/concerns/email-compose.notifications.held.cancelled_body', [
                'subject' => 'Next steps',
                'recipients' => '',
                'workspace' => e($this->workspace->name),
            ]));
    });

    it('says a held email that failed to send was not sent', function (): void {
        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, 'Claude', 300);
        $this->held->update(['status' => EmailStatus::FAILED]);

        $this->travelTo(now()->addSeconds(301));

        $notice = EmailSendHeldNotification::present($this->owner->notifications()->sole());

        expect($notice->getActions())->toBe([])
            ->and($notice->getBody())->toBe(__('filament/concerns/email-compose.notifications.held.failed_body', [
                'subject' => 'Next steps',
                'recipients' => '',
                'workspace' => e($this->workspace->name),
            ]));
    });

    it('says a held email was sent once it is sent', function (): void {
        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, 'Claude', 300);
        $this->held->update(['status' => EmailStatus::SENT]);

        $notice = EmailSendHeldNotification::present($this->owner->notifications()->sole());

        expect($notice->getActions())->toBe([])
            ->and($notice->getBody())->toBe(__('filament/concerns/email-compose.notifications.held.sent_body', [
                'subject' => 'Next steps',
                'recipients' => '',
                'workspace' => e($this->workspace->name),
            ]));
    });

    it('follows the clock when the held email can no longer be loaded', function (): void {
        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, 'Claude', 300);

        $row = $this->owner->notifications()->sole()->setRelation('subject', null);

        expect(EmailSendHeldNotification::present($row)->getActions())->toHaveCount(1);

        $this->travelTo(now()->addSeconds(301));

        expect(EmailSendHeldNotification::present($row)->getActions())->toBe([]);
    });

    it('shows the held notice in the bell with cancel until the email is sent', function (): void {
        resolve(CurrentWorkspace::class)->set($this->workspace);
        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, 'Claude', 300);

        Livewire::actingAs($this->owner)->test(AppDatabaseNotifications::class)
            ->assertSee('Claude queued an email')
            ->assertSee(__('filament/concerns/email-compose.actions.cancel_send.label'));

        $this->held->update(['status' => EmailStatus::SENT]);
        $this->travelTo(now()->addSeconds(301));

        Livewire::actingAs($this->owner)->test(AppDatabaseNotifications::class)
            ->assertSee('Claude queued an email')
            ->assertDontSee(__('filament/concerns/email-compose.actions.cancel_send.label'));
    });

    it('stores the notice at once instead of queueing it', function (): void {
        Queue::fake();

        resolve(QueuedSendNotifier::class)->sendHeld($this->held, $this->owner, 'Claude', 300);

        expect($this->owner->notifications()->count())->toBe(1);
        Queue::assertNotPushed(SendQueuedNotifications::class);
    });
});
