<?php

declare(strict_types=1);

use App\Models\User;
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
    it('includes a clickable email subject and accept and decline actions', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        $payload = (new EmailAccessRequestedNotification($request))->toDatabase($this->owner);
        $actions = collect($payload['actions'] ?? [])->keyBy('name');

        expect($actions->keys()->all())->toBe(['viewEmail', 'accept', 'decline'])
            ->and($actions['viewEmail']['label'])->toBe('Q4 pipeline review')
            ->and($actions['viewEmail']['alpineClickHandler'])->toContain('open-email-from-access-request')
            ->and($actions['viewEmail']['alpineClickHandler'])->toContain('close-modal')
            ->and($actions['viewEmail']['alpineClickHandler'])->toContain('window.Livewire.dispatchTo')
            ->and($actions['accept']['label'])->toBe('Accept')
            ->and($actions['accept']['alpineClickHandler'])->toContain('approve-email-access-request')
            ->and($actions['accept']['alpineClickHandler'])->toContain('window.Livewire.dispatchTo')
            ->and($actions['decline']['label'])->toBe('Decline')
            ->and($actions['decline']['alpineClickHandler'])->toContain('deny-email-access-request')
            ->and($actions['decline']['alpineClickHandler'])->toContain('window.Livewire.dispatchTo');
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
    it('opens the email reader modal from the notification event', function (): void {
        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertActionMounted('view');
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
            ->assertNotified('Access request approved.');

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
            ->assertNotified('Access request denied.');

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('closes the email reader after approving from the reader bar event', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->dispatch('open-email-from-access-request', emailId: $this->email->getKey())
            ->assertActionMounted('view')
            ->dispatch('approve-email-access-request', requestId: $request->getKey())
            ->assertActionNotMounted('view')
            ->assertNotified('Access request approved.');

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('approves a pending request from the reader bar', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->call('approveAccessRequestInline', $request->getKey())
            ->assertNotified('Access request approved.');

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });

    it('denies a pending request from the reader bar', function (): void {
        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->call('denyAccessRequestInline', $request->getKey())
            ->assertNotified('Access request denied.');

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
    });

    it('ignores inline approve for requests the viewer does not own', function (): void {
        $intruder = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->actingAs($intruder);

        $request = EmailAccessRequest::factory()->forTier(EmailPrivacyTier::FULL)->create([
            'requester_id' => $this->requester->id,
            'owner_id' => $this->owner->id,
            'email_id' => $this->email->getKey(),
        ]);

        NotificationFacade::fake();

        Livewire::test(EmailAccessNotificationHandler::class)
            ->call('approveAccessRequestInline', $request->getKey())
            ->assertNotNotified();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });

    it('ignores approve notification events for requests the viewer does not own', function (): void {
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
