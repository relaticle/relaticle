<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailOutboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(EmailOutboxPage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));
});

function makeOutboxEmail(User $user, ConnectedAccount $account, EmailStatus $status, array $overrides = []): Email
{
    return Email::create(array_merge([
        'team_id' => $user->currentTeam->id,
        'user_id' => $user->id,
        'connected_account_id' => $account->id,
        'subject' => 'Outbox row',
        'direction' => EmailDirection::OUTBOUND,
        'status' => $status,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ], $overrides));
}

it('renders the page for an authenticated user', function (): void {
    livewire(EmailOutboxPage::class)->assertSuccessful();
});

it('queued tab shows only this user\'s queued OUTBOUND emails', function (): void {
    $mine = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED);

    $otherUser = User::factory()->withTeam()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $otherUser->currentTeam->id,
        'user_id' => $otherUser->id,
    ]));
    $theirs = makeOutboxEmail($otherUser, $otherAccount, EmailStatus::QUEUED);

    livewire(EmailOutboxPage::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$failed, $theirs]);
});

it('failed tab filters to failed emails', function (): void {
    $queued = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED, [
        'last_error' => 'SMTP bounce',
    ]);

    livewire(EmailOutboxPage::class)
        ->filterTable('status_tab', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$queued]);
});

it('cancel row action moves a queued email to CANCELLED', function (): void {
    $email = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    livewire(EmailOutboxPage::class)
        ->callAction(TestAction::make('cancel')->table($email))
        ->assertNotified();

    expect($email->refresh()->status)->toBe(EmailStatus::CANCELLED);
});

it('cancel row action is hidden for non-queued emails', function (): void {
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED);

    livewire(EmailOutboxPage::class)
        ->filterTable('status_tab', 'failed')
        ->assertActionHidden(TestAction::make('cancel')->table($failed));
});

it('reschedule row action updates scheduled_for', function (): void {
    $email = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    $target = now()->addDay()->startOfMinute();

    livewire(EmailOutboxPage::class)
        ->callAction(
            TestAction::make('reschedule')->table($email),
            data: ['scheduled_for' => $target->toDateTimeString()],
        )
        ->assertNotified();

    expect($email->refresh()->scheduled_for?->timestamp)->toBe($target->timestamp);
});

it('retry row action re-queues a failed email', function (): void {
    $email = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED, [
        'last_error' => 'timeout',
        'attempts' => 3,
    ]);

    livewire(EmailOutboxPage::class)
        ->filterTable('status_tab', 'failed')
        ->assertCanSeeTableRecords([$email])
        ->callAction([['name' => 'retry', 'context' => ['table' => true, 'recordKey' => $email->getKey()]]])
        ->assertNotified();

    expect($email->refresh())
        ->status->toBe(EmailStatus::QUEUED)
        ->last_error->toBeNull()
        ->attempts->toBe(0);
});

it('retry row action is hidden for queued emails', function (): void {
    $email = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    livewire(EmailOutboxPage::class)
        ->assertActionHidden(TestAction::make('retry')->table($email));
});

it('bulkCancel cancels selected queued rows', function (): void {
    $queuedA = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $queuedB = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    livewire(EmailOutboxPage::class)
        ->selectTableRecords([$queuedA, $queuedB])
        ->callAction([['name' => 'bulkCancel', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertNotified();

    expect($queuedA->refresh()->status)->toBe(EmailStatus::CANCELLED)
        ->and($queuedB->refresh()->status)->toBe(EmailStatus::CANCELLED);
});
