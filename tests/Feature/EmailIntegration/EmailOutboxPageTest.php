<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Livewire\OutboxTable;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(OutboxTable::class);

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
    return Email::query()->create(array_merge([
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

it('does not expose Outbox in workspace settings', function (): void {
    $this->get("/app/{$this->team->slug}/email-settings/outbox")
        ->assertNotFound();
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

    livewire(OutboxTable::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$failed, $theirs]);
});

it('failed tab filters to failed emails', function (): void {
    $queued = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED, [
        'last_error' => 'SMTP bounce',
    ]);

    livewire(OutboxTable::class)
        ->filterTable('status_tab', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$queued]);
});

it('locked failed mode lists every failed email owned by the user', function (): void {
    $firstAccountFailure = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED, [
        'last_error' => 'SMTP bounce',
    ]);
    $firstAccountFailure->forceFill(['created_at' => now()->subYears(3)])->saveQuietly();
    $secondAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));
    $secondAccountFailure = makeOutboxEmail($this->user, $secondAccount, EmailStatus::FAILED, [
        'last_error' => 'Provider timeout',
    ]);
    $queued = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    $teammate = User::factory()->create(['current_team_id' => $this->team->id]);
    $teammateAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $teammate->id,
    ]));
    $teammateFailure = makeOutboxEmail($teammate, $teammateAccount, EmailStatus::FAILED);

    $otherUser = User::factory()->withTeam()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $otherUser->current_team_id,
        'user_id' => $otherUser->id,
    ]));
    $otherTeamFailure = makeOutboxEmail($otherUser, $otherAccount, EmailStatus::FAILED);

    $component = livewire(OutboxTable::class, ['lockedStatus' => EmailStatus::FAILED])
        ->assertCanSeeTableRecords([$firstAccountFailure, $secondAccountFailure])
        ->assertCanNotSeeTableRecords([$queued, $teammateFailure, $otherTeamFailure])
        ->assertTableColumnVisible('last_error');

    expect($component->instance()->getTable()->getFilter('status_tab'))->toBeNull();
});

it('locked failed mode has a dedicated empty state', function (): void {
    $component = livewire(OutboxTable::class, ['lockedStatus' => EmailStatus::FAILED])
        ->assertSee(__('filament/pages/email-inbox.failed.empty.heading'))
        ->assertSee(__('filament/pages/email-inbox.failed.empty.description'));

    expect($component->instance()->getTable()->getEmptyStateIcon())
        ->toBe(Heroicon::OutlinedExclamationCircle);
});

it('uses an outlined clock for the outbox empty state', function (): void {
    $component = livewire(OutboxTable::class, ['includeFailedFilter' => false])
        ->assertSee(__('filament/pages/email-inbox.outbox.empty.heading'))
        ->assertSee(__('filament/pages/email-inbox.outbox.empty.description'));

    expect($component->instance()->getTable()->getEmptyStateIcon())
        ->toBe(Heroicon::OutlinedClock);
});

it('can exclude failed from the status filter without changing standalone outbox', function (): void {
    $emailPageOptions = livewire(OutboxTable::class, ['includeFailedFilter' => false])
        ->instance()
        ->getTable()
        ->getFilter('status_tab')
        ?->getOptions();

    $standaloneOptions = livewire(OutboxTable::class)
        ->instance()
        ->getTable()
        ->getFilter('status_tab')
        ?->getOptions();

    expect($emailPageOptions)
        ->not->toHaveKey('failed')
        ->and($standaloneOptions)->toHaveKey('failed');
});

it('cancel row action moves a queued email to CANCELLED', function (): void {
    $email = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    livewire(OutboxTable::class)
        ->callAction(TestAction::make('cancel')->table($email))
        ->assertNotified()
        ->assertDispatched('outbox:changed');

    expect($email->refresh()->status)->toBe(EmailStatus::CANCELLED);
});

it('cancel row action is hidden for non-queued emails', function (): void {
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED);

    livewire(OutboxTable::class)
        ->filterTable('status_tab', 'failed')
        ->assertActionHidden(TestAction::make('cancel')->table($failed));
});

it('reschedule row action updates scheduled_for', function (): void {
    $email = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    $target = now()->addDay()->startOfMinute();

    livewire(OutboxTable::class)
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

    livewire(OutboxTable::class)
        ->filterTable('status_tab', 'failed')
        ->assertCanSeeTableRecords([$email])
        ->callAction([['name' => 'retry', 'context' => ['table' => true, 'recordKey' => $email->getKey()]]])
        ->assertNotified()
        ->assertDispatched('outbox:changed');

    expect($email->refresh())
        ->status->toBe(EmailStatus::QUEUED)
        ->last_error->toBeNull()
        // attempts is intentionally preserved (not reset to 0): EmailSendingService only
        // runs its provider-side dedup lookup when attempts > 1, so zeroing it here would
        // let a retry re-deliver an email a prior attempt already handed to the provider.
        ->attempts->toBe(3);
});

it('retry row action is hidden for queued emails', function (): void {
    $email = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    livewire(OutboxTable::class)
        ->assertActionHidden(TestAction::make('retry')->table($email));
});

it('bulkCancel cancels selected queued rows', function (): void {
    $queuedA = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $queuedB = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    livewire(OutboxTable::class)
        ->selectTableRecords([$queuedA, $queuedB])
        ->callAction([['name' => 'bulkCancel', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertNotified()
        ->assertDispatched('outbox:changed');

    expect($queuedA->refresh()->status)->toBe(EmailStatus::CANCELLED)
        ->and($queuedB->refresh()->status)->toBe(EmailStatus::CANCELLED);
});

it('bulkCancel skips a row that raced to SENDING and still cancels the rest', function (): void {
    $queuedA = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $racing = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $queuedB = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    $component = livewire(OutboxTable::class)
        ->selectTableRecords([$queuedA, $racing, $queuedB]);

    // Simulate the row transitioning QUEUED -> SENDING between render and the
    // bulk action firing. The action re-locks the row and throws RuntimeException;
    // the loop must catch it, skip that row, and still cancel the others.
    Email::withoutEvents(fn () => Email::query()->whereKey($racing->getKey())->update(['status' => EmailStatus::SENDING]));

    $component
        ->callAction([['name' => 'bulkCancel', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertNotified();

    expect($queuedA->refresh()->status)->toBe(EmailStatus::CANCELLED)
        ->and($queuedB->refresh()->status)->toBe(EmailStatus::CANCELLED)
        ->and($racing->refresh()->status)->toBe(EmailStatus::SENDING);
});
