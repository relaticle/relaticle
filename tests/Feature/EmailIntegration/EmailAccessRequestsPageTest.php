<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Livewire\AccessRequestsTable;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

mutates(AccessRequestsTable::class, EmailAccessRequestsPage::class);

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

        $request = EmailAccessRequest::factory()->pending()->create([
            'owner_id' => $this->user->id,
            'requester_id' => $requester->id,
            'email_id' => $this->email->getKey(),
        ]);

        livewire(AccessRequestsTable::class)
            ->callTableAction('approveAccessRequest', $request)
            ->assertNotified('Access request approved.');
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

        livewire(AccessRequestsTable::class)
            ->callTableAction('denyAccessRequest', $request)
            ->assertNotified('Access request denied.');
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

        livewire(AccessRequestsTable::class)
            ->call('setTab', 'outgoing')
            ->callTableAction('cancelAccessRequest', $request)
            ->assertNotified('Access request cancelled.');

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
