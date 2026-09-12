<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Livewire\AccessRequestsTable;
use Relaticle\EmailIntegration\Livewire\Concerns\InteractsWithEmailAccessRequests;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Notifications\EmailAccessRequestedNotification;
use Relaticle\EmailIntegration\Services\EmailSearchService;

mutates(AccessRequestsTable::class, EmailAccessRequestsPage::class, InteractsWithEmailAccessRequests::class, EmailSearchService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $this->email = Email::factory()->private()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
    ]);
});

describe('Tab switching', function (): void {
    it('shows incoming requests in a table with the available review actions', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->assertCanSeeTableRecords([$request])
            ->assertTableActionVisible('approveAccessRequest', $request)
            ->assertTableActionVisible('denyAccessRequest', $request)
            ->assertTableActionHidden('cancelAccessRequest', $request);
    });

    it('defaults to incoming tab and shows requests where user is owner', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $incomingRequest = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        $requesterAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $requester->id,
        ]));

        $otherEmail = Email::factory()->private()->create([
            'team_id' => $this->team->id,
            'user_id' => $requester->id,
            'connected_account_id' => $requesterAccount->getKey(),
        ]);

        $outgoingRequest = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $requester->id,
            'requester_id' => $this->user->id,
            'email_id' => $otherEmail->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->assertCanSeeTableRecords([$incomingRequest])
            ->assertCanNotSeeTableRecords([$outgoingRequest]);
    });

    it('shows outgoing requests after switching to outgoing tab', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $incomingRequest = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        $requesterAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $requester->id,
        ]));

        $otherEmail = Email::factory()->private()->create([
            'team_id' => $this->team->id,
            'user_id' => $requester->id,
            'connected_account_id' => $requesterAccount->getKey(),
        ]);

        $outgoingRequest = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $requester->id,
            'requester_id' => $this->user->id,
            'email_id' => $otherEmail->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->call('setTab', 'outgoing')
            ->assertCanSeeTableRecords([$outgoingRequest])
            ->assertCanNotSeeTableRecords([$incomingRequest]);
    });

});

describe('approveAccessRequest action', function (): void {
    it('approves a pending request and sends a notification', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($requester, ['role' => 'editor']);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->user->notify(new EmailAccessRequestedNotification($request));

        livewire(AccessRequestsTable::class)
            ->callTableAction('approveAccessRequest', $request)
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified('Access request approved.');

        expect($this->user->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('does nothing when a non-owner passes a request id', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id]);
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $otherEmail = Email::factory()->private()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'connected_account_id' => $this->account->getKey(),
        ]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'email_id' => $otherEmail->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->assertCanNotSeeTableRecords([$request]);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });
});

describe('denyAccessRequest action', function (): void {
    it('denies a pending request and sends a notification', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        $this->user->notify(new EmailAccessRequestedNotification($request));

        livewire(AccessRequestsTable::class)
            ->callTableAction('denyAccessRequest', $request)
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified('Access request denied.');

        expect($this->user->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('does nothing when a non-owner passes a request id', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id]);
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $otherEmail = Email::factory()->private()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'connected_account_id' => $this->account->getKey(),
        ]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'email_id' => $otherEmail->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->assertCanNotSeeTableRecords([$request]);

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });
});

describe('cancelAccessRequest action', function (): void {
    it('deletes a pending outgoing request', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id]);

        $ownerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
        ]));

        $otherEmail = Email::factory()->private()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'connected_account_id' => $ownerAccount->getKey(),
        ]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $owner->id,
            'requester_id' => $this->user->id,
            'email_id' => $otherEmail->getKey(),
        ]);

        $owner->notify(new EmailAccessRequestedNotification($request));

        livewire(AccessRequestsTable::class)
            ->call('setTab', 'outgoing')
            ->callTableAction('cancelAccessRequest', $request)
            ->assertDispatched('databaseNotificationsSent')
            ->assertNotified('Access request cancelled.');

        expect(EmailAccessRequest::query()->whereKey($request->id)->exists())->toBeFalse()
            ->and($owner->notifications()->where('type', EmailAccessRequestedNotification::class)->count())->toBe(0);
    });

    it('does nothing when a non-requester passes a request id', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id]);
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $ownerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
        ]));

        $otherEmail = Email::factory()->private()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'connected_account_id' => $ownerAccount->getKey(),
        ]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $owner->id,
            'requester_id' => $requester->id,
            'email_id' => $otherEmail->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->assertCanNotSeeTableRecords([$request]);

        expect(EmailAccessRequest::query()->whereKey($request->id)->exists())->toBeTrue();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });

    it('does not delete an approved request', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id]);

        $ownerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
        ]));

        $otherEmail = Email::factory()->private()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'connected_account_id' => $ownerAccount->getKey(),
        ]);

        $request = EmailAccessRequest::factory()->approved()->create([
            'owner_id' => $owner->id,
            'requester_id' => $this->user->id,
            'email_id' => $otherEmail->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->call('setTab', 'outgoing')
            ->assertTableActionHidden('cancelAccessRequest', $request);

        expect(EmailAccessRequest::query()->whereKey($request->id)->exists())->toBeTrue();
        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED);
    });
});

describe('getNavigationBadge', function (): void {
    it('returns the count of pending incoming requests as a string', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        expect(EmailAccessRequestsPage::getNavigationBadge())->toBe('2');
    });

    it('returns null when there are no pending incoming requests', function (): void {
        expect(EmailAccessRequestsPage::getNavigationBadge())->toBeNull();
    });

    it('does not count approved or denied requests', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        EmailAccessRequest::factory()->approved()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        EmailAccessRequest::factory()->denied()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        expect(EmailAccessRequestsPage::getNavigationBadge())->toBeNull();
    });

    it('does not count outgoing pending requests in the badge', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id]);

        EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $owner->id,
            'requester_id' => $this->user->id,
            'email_id' => $this->email->getKey(),
        ]);

        expect(EmailAccessRequestsPage::getNavigationBadge())->toBeNull();
    });
});

describe('subject privacy', function (): void {
    it('hides the email subject on outgoing requests when the viewer cannot see it', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id]);

        $ownerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
        ]));

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'connected_account_id' => $ownerAccount->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
            'subject' => 'Confidential Thread XYZ',
        ]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $owner->id,
            'requester_id' => $this->user->id,
            'email_id' => $email->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->call('setTab', 'outgoing')
            ->assertCanSeeTableRecords([$request])
            ->assertTableColumnStateSet('email.subject', __('filament/pages/email-access-requests.request.subject_hidden'), $request)
            ->assertDontSee('Confidential Thread XYZ');
    });

    it('hides the email subject on outgoing requests after access is denied', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id]);

        $ownerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
        ]));

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'connected_account_id' => $ownerAccount->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
            'subject' => 'Denied Thread Secret',
        ]);

        $request = EmailAccessRequest::factory()->denied()->create([
            'owner_id' => $owner->id,
            'requester_id' => $this->user->id,
            'email_id' => $email->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->call('setTab', 'outgoing')
            ->assertCanSeeTableRecords([$request])
            ->assertTableColumnStateSet('email.subject', __('filament/pages/email-access-requests.request.subject_hidden'), $request)
            ->assertDontSee('Denied Thread Secret');
    });

    it('shows the email subject on outgoing requests after access is approved', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id]);

        $ownerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
        ]));

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'connected_account_id' => $ownerAccount->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
            'subject' => 'Approved Thread Subject',
        ]);

        $request = EmailAccessRequest::factory()->approved()->create([
            'owner_id' => $owner->id,
            'requester_id' => $this->user->id,
            'email_id' => $email->getKey(),
        ]);

        EmailShare::factory()->tier(EmailPrivacyTier::FULL)->create([
            'email_id' => $email->getKey(),
            'team_id' => $this->team->id,
            'shared_by' => $owner->id,
            'shared_with' => $this->user->id,
        ]);

        livewire(AccessRequestsTable::class)
            ->call('setTab', 'outgoing')
            ->assertTableColumnStateSet('email.subject', 'Approved Thread Subject', $request);
    });

    it('shows the email subject on outgoing requests when the viewer already has subject access', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id]);

        $ownerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
        ]));

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'connected_account_id' => $ownerAccount->getKey(),
            'privacy_tier' => EmailPrivacyTier::SUBJECT,
            'subject' => 'Visible Subject Line',
        ]);

        $request = EmailAccessRequest::factory()->pending()->forTier(EmailPrivacyTier::FULL)->create([
            'owner_id' => $owner->id,
            'requester_id' => $this->user->id,
            'email_id' => $email->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->call('setTab', 'outgoing')
            ->assertTableColumnStateSet('email.subject', 'Visible Subject Line', $request);
    });

    it('shows the email subject on incoming requests for the owner', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
            'subject' => 'Owner Visible Subject',
        ]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $email->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->assertTableColumnStateSet('email.subject', 'Owner Visible Subject', $request);
    });

    it('does not match a hidden subject when searching outgoing requests', function (): void {
        $owner = User::factory()->create(['current_team_id' => $this->team->id, 'name' => 'Pat Owner']);

        $ownerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
        ]));

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $owner->id,
            'connected_account_id' => $ownerAccount->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
            'subject' => 'Quarterly forecast secret',
        ]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $owner->id,
            'requester_id' => $this->user->id,
            'email_id' => $email->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->call('setTab', 'outgoing')
            ->searchTable('Quarterly forecast secret')
            ->assertCanNotSeeTableRecords([$request])
            ->searchTable('Pat Owner')
            ->assertCanSeeTableRecords([$request]);
    });

    it('matches the subject when searching incoming requests as the owner', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
            'subject' => 'Owner search subject',
        ]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $email->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->searchTable('Owner search subject')
            ->assertCanSeeTableRecords([$request]);
    });
});
