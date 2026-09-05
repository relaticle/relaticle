<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Actions\RequestEmailAccessAction;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;

mutates(EmailAccessNotificationHandler::class, EmailAccessRequestedNotification::class, RequestEmailAccessAction::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->actingAs($this->owner);
    $this->team = $this->owner->currentTeam;
    Filament::setTenant($this->team);

    $this->requester = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($this->requester, ['role' => 'editor']);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->email = Email::factory()->private()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'subject' => 'Q4 pipeline review',
    ]);
});

describe('EmailAccessRequestedNotification', function (): void {
    it('opens the emails-tab reader overlay from view without dismissing the notification', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $payload = (new EmailAccessRequestedNotification($request))->toDatabase($this->owner);
        $actions = collect($payload['actions'] ?? [])->keyBy('name');

        $viewHandler = $actions['viewEmail']['alpineClickHandler'];
        $acceptHandler = $actions['accept']['alpineClickHandler'];
        $declineHandler = $actions['decline']['alpineClickHandler'];

        expect($actions->keys()->all())->toBe(['viewEmail', 'accept', 'decline'])
            ->and($payload['body'])->toBe('Q4 pipeline review')
            ->and($actions['viewEmail']['label'])->toBe('View')
            ->and($actions['viewEmail']['shouldMarkAsRead'] ?? false)->toBeFalse()
            ->and($actions['viewEmail']['shouldClose'] ?? false)->toBeFalse()
            ->and($viewHandler)->toContain('window.Livewire.dispatchTo')
            ->and($viewHandler)->toContain(EmailAccessNotificationHandler::LIVEWIRE_ALIAS)
            ->and($viewHandler)->toContain('open-email-from-access-request')
            ->and($viewHandler)->toContain($this->email->getKey())
            ->and($viewHandler)->toContain('close-modal')
            ->and($viewHandler)->not->toContain('close();')
            ->and($viewHandler)->not->toContain('markAsRead()')
            ->and($actions['accept']['label'])->toBe('Accept')
            ->and($actions['accept']['color'])->toBe('success')
            ->and($actions['accept']['shouldMarkAsRead'])->toBeTrue()
            ->and($acceptHandler)->toContain('approve-email-access-request')
            ->and($acceptHandler)->toContain('window.Livewire.dispatchTo')
            ->and($acceptHandler)->toContain('markAsRead()')
            ->and($acceptHandler)->not->toContain('close();')
            ->and($actions['decline']['label'])->toBe('Decline')
            ->and($actions['decline']['color'])->toBe('danger')
            ->and($actions['decline']['shouldMarkAsRead'])->toBeTrue()
            ->and($declineHandler)->toContain('deny-email-access-request')
            ->and($declineHandler)->toContain('window.Livewire.dispatchTo')
            ->and($declineHandler)->toContain('markAsRead()')
            ->and($declineHandler)->not->toContain('close();');
    });

    it('is sent when a teammate requests access', function (): void {
        NotificationFacade::fake();

        app(RequestEmailAccessAction::class)->execute(
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
            ->assertSee('fi-email-reader-panel');
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

    it('approves a pending request from the notification event', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('approve-email-access-request', requestId: $request->getKey())
            ->assertSet('selectedEmailId', null)
            ->assertNotified(__('filament/pages/email-access-requests.notifications.approved'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('denies a pending request from the notification event', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('deny-email-access-request', requestId: $request->getKey())
            ->assertSet('selectedEmailId', null)
            ->assertNotified(__('filament/pages/email-access-requests.notifications.denied'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
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
            ->assertNotified(__('filament/pages/email-access-requests.notifications.denied'));

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('ignores approve for requests the viewer does not own', function (): void {
        $intruder = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->actingAs($intruder);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('approve-email-access-request', requestId: $request->getKey())
            ->assertNotNotified();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });
});
