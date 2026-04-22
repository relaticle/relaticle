<?php

declare(strict_types=1);

use App\Filament\Pages\EmailAccessRequestsPage;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

mutates(EmailAccessRequestsPage::class);

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

        $ids = livewire(EmailAccessRequestsPage::class)
            ->get('requests')
            ->pluck('id')
            ->all();

        expect($ids)
            ->toContain($incomingRequest->id)
            ->not->toContain($outgoingRequest->id);
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

        $ids = livewire(EmailAccessRequestsPage::class)
            ->call('setTab', 'outgoing')
            ->get('requests')
            ->pluck('id')
            ->all();

        expect($ids)
            ->toContain($outgoingRequest->id)
            ->not->toContain($incomingRequest->id);
    });

    it('clears selectedRequestId when switching tabs', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(EmailAccessRequestsPage::class)
            ->call('selectRequest', (string) $request->id)
            ->assertSet('selectedRequestId', (string) $request->id)
            ->call('setTab', 'outgoing')
            ->assertSet('selectedRequestId', null);
    });
});

describe('selectRequest', function (): void {
    it('sets selectedRequestId to the given request id', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(EmailAccessRequestsPage::class)
            ->call('selectRequest', (string) $request->id)
            ->assertSet('selectedRequestId', (string) $request->id);
    });
});

describe('approveAccessRequest action', function (): void {
    it('approves a pending request, sends notification, and clears selectedRequestId', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(EmailAccessRequestsPage::class)
            ->call('selectRequest', (string) $request->id)
            ->callAction('approveAccessRequest', arguments: ['requestId' => $request->id])
            ->assertNotified('Access request approved.')
            ->assertSet('selectedRequestId', null);
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

        // Current user ($this->user) is not the owner — action should be a no-op
        livewire(EmailAccessRequestsPage::class)
            ->callAction('approveAccessRequest', arguments: ['requestId' => $request->id])
            ->assertNotNotified();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });
});

describe('denyAccessRequest action', function (): void {
    it('denies a pending request, sends notification, and clears selectedRequestId', function (): void {
        $requester = User::factory()->create(['current_team_id' => $this->team->id]);

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(EmailAccessRequestsPage::class)
            ->call('selectRequest', (string) $request->id)
            ->callAction('denyAccessRequest', arguments: ['requestId' => $request->id])
            ->assertNotified('Access request denied.')
            ->assertSet('selectedRequestId', null);
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

        // Current user ($this->user) is not the owner — action should be a no-op
        livewire(EmailAccessRequestsPage::class)
            ->callAction('denyAccessRequest', arguments: ['requestId' => $request->id])
            ->assertNotNotified();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
    });
});

describe('cancelAccessRequest action', function (): void {
    it('deletes a pending outgoing request and clears selectedRequestId', function (): void {
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

        livewire(EmailAccessRequestsPage::class)
            ->call('setTab', 'outgoing')
            ->call('selectRequest', (string) $request->id)
            ->callAction('cancelAccessRequest', arguments: ['requestId' => $request->id])
            ->assertNotified('Access request cancelled.')
            ->assertSet('selectedRequestId', null);

        expect(EmailAccessRequest::query()->whereKey($request->id)->exists())->toBeFalse();
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

        // Current user ($this->user) is neither owner nor requester — action should be a no-op
        livewire(EmailAccessRequestsPage::class)
            ->callAction('cancelAccessRequest', arguments: ['requestId' => $request->id])
            ->assertNotNotified();

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

        livewire(EmailAccessRequestsPage::class)
            ->call('setTab', 'outgoing')
            ->callAction('cancelAccessRequest', arguments: ['requestId' => $request->id]);

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
