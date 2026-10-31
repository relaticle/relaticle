<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Translation\Translator;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Enums\OutboxTab;
use Relaticle\EmailIntegration\Livewire\OutboxTable;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(OutboxTable::class, OutboxTab::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
});

function makeOutboxEmail(User $user, ConnectedAccount $account, EmailStatus $status, array $overrides = []): Email
{
    return Email::query()->create(array_merge([
        'workspace_id' => $user->currentWorkspace->id,
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
    $this->get("/app/{$this->workspace->slug}/workspace/email/outbox")
        ->assertNotFound();
});

it('queued tab shows only this user\'s queued OUTBOUND emails', function (): void {
    $mine = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED);

    $otherUser = User::factory()->withWorkspace()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $otherUser->currentWorkspace->id,
        'user_id' => $otherUser->id,
    ]));
    $theirs = makeOutboxEmail($otherUser, $otherAccount, EmailStatus::QUEUED);

    livewire(OutboxTable::class)
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$failed, $theirs]);
});

it('shows a just-sent email in the queued tab during the undo window', function (): void {
    $this->travelTo(now()->startOfSecond());

    $justSent = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, [
        'subject' => 'Just composed',
        'scheduled_for' => now()->addSeconds(5),
    ]);

    livewire(OutboxTable::class)
        ->assertCanSeeTableRecords([$justSent]);
});

it('refreshes the queued list when a send is queued', function (): void {
    $this->travelTo(now()->startOfSecond());

    $component = livewire(OutboxTable::class);

    $justSent = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, [
        'subject' => 'Arrived after render',
        'scheduled_for' => now()->addSeconds(5),
    ]);

    $component
        ->dispatch('outbox:changed')
        ->assertCanSeeTableRecords([$justSent]);
});

it('keeps a later send on the scheduled tab instead of queued', function (): void {
    $this->travelTo(now()->startOfSecond());

    $undoWindow = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, [
        'subject' => 'Sending in 5 seconds',
        'scheduled_for' => now()->addSeconds(5),
    ]);
    $later = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, [
        'subject' => 'Send tomorrow',
        'scheduled_for' => now()->addDay(),
    ]);

    livewire(OutboxTable::class)
        ->assertCanSeeTableRecords([$undoWindow])
        ->assertCanNotSeeTableRecords([$later])
        ->call('setStatusTab', 'scheduled')
        ->assertCanSeeTableRecords([$later])
        ->assertCanNotSeeTableRecords([$undoWindow]);
});

it('failed tab filters to failed emails', function (): void {
    $queued = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED, [
        'last_error' => 'SMTP bounce',
    ]);

    livewire(OutboxTable::class)
        ->call('setStatusTab', 'failed')
        ->assertCanSeeTableRecords([$failed])
        ->assertCanNotSeeTableRecords([$queued]);
});

it('locked failed mode lists every failed email owned by the user', function (): void {
    $firstAccountFailure = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED, [
        'last_error' => 'SMTP bounce',
    ]);
    $firstAccountFailure->forceFill(['created_at' => now()->subYears(3)])->saveQuietly();
    $secondAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    $secondAccountFailure = makeOutboxEmail($this->user, $secondAccount, EmailStatus::FAILED, [
        'last_error' => 'Provider timeout',
    ]);
    $queued = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);

    $teammate = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $teammateAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $teammate->id,
    ]));
    $teammateFailure = makeOutboxEmail($teammate, $teammateAccount, EmailStatus::FAILED);

    $otherUser = User::factory()->withWorkspace()->create();
    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $otherUser->current_workspace_id,
        'user_id' => $otherUser->id,
    ]));
    $otherTeamFailure = makeOutboxEmail($otherUser, $otherAccount, EmailStatus::FAILED);

    $component = livewire(OutboxTable::class, ['lockedStatus' => EmailStatus::FAILED])
        ->assertCanSeeTableRecords([$firstAccountFailure, $secondAccountFailure])
        ->assertCanNotSeeTableRecords([$queued, $teammateFailure, $otherTeamFailure])
        ->assertTableColumnVisible('last_error');

    expect($component->instance()->segments())->toBe([]);
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

it('can exclude failed from the segments without changing standalone outbox', function (): void {
    $emailPageSegments = livewire(OutboxTable::class, ['includeFailedFilter' => false])->instance()->segments();
    $standaloneSegments = livewire(OutboxTable::class)->instance()->segments();

    expect($emailPageSegments)
        ->not->toContain(OutboxTab::FAILED)
        ->and($standaloneSegments)->toContain(OutboxTab::FAILED);
});

function overrideOutboxLines(array $lines): void
{
    __('filament/pages/email-outbox.title');

    resolve(Translator::class)->addLines(collect($lines)
        ->mapWithKeys(fn (string $line, string $key): array => ["filament/pages/email-outbox.{$key}" => $line])
        ->all(), 'en');
}

it('shows statuses as labelled segments, not as a removable filter chip', function (): void {
    overrideOutboxLines([
        'tabs.scheduled' => 'Segment later sends',
        'columns.subject' => 'Column subject line',
        'columns.status' => 'Column delivery state',
        'columns.last_error' => 'Column provider error',
    ]);

    $component = livewire(OutboxTable::class);

    expect($component->instance()->getTable()->getFilter('status_tab'))->toBeNull();

    $component->assertDontSee('Status tab')
        ->assertSee('Segment later sends')
        ->assertSee('Column subject line')
        ->assertSee('Column delivery state')
        ->assertTableColumnExists('last_error', fn (TextColumn $column): bool => $column->getLabel() === 'Column provider error');
});

it('labels every row action', function (): void {
    overrideOutboxLines([
        'actions.cancel' => 'Stop this send',
        'actions.reschedule' => 'Pick another time',
        'actions.retry' => 'Send it again',
    ]);

    $queued = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED);

    livewire(OutboxTable::class)
        ->assertActionHasLabel(TestAction::make('cancel')->table($queued), 'Stop this send')
        ->assertActionHasLabel(TestAction::make('reschedule')->table($queued), 'Pick another time')
        ->call('setStatusTab', 'failed')
        ->assertActionHasLabel(TestAction::make('retry')->table($failed), 'Send it again');
});

it('falls back to the queued segment for an unknown status in the URL', function (): void {
    $queued = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED);

    Livewire::withQueryParams(['status' => 'bogus'])
        ->test(OutboxTable::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$queued])
        ->assertCanNotSeeTableRecords([$failed]);
});

it('falls back to the queued segment for an unknown status', function (): void {
    $queued = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED);
    $failed = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED);

    livewire(OutboxTable::class)
        ->call('setStatusTab', 'failed')
        ->call('setStatusTab', 'bogus')
        ->assertCanSeeTableRecords([$queued])
        ->assertCanNotSeeTableRecords([$failed]);
});

it('lists scheduled sends soonest first', function (): void {
    $this->travelTo(now()->startOfSecond());

    $later = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, ['scheduled_for' => now()->addHours(3)]);
    $soonest = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, ['scheduled_for' => now()->addHour()]);
    $middle = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, ['scheduled_for' => now()->addHours(2)]);

    livewire(OutboxTable::class)
        ->call('setStatusTab', 'scheduled')
        ->assertCanSeeTableRecords([$soonest, $middle, $later], inOrder: true);
});

it('lists queued sends with no send time first, then soonest first', function (): void {
    $this->travelTo(now()->startOfSecond());

    $later = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, ['scheduled_for' => now()->addSeconds(5)]);
    $unscheduled = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, ['scheduled_for' => null]);
    $soonest = makeOutboxEmail($this->user, $this->account, EmailStatus::QUEUED, ['scheduled_for' => now()->addSeconds(2)]);

    livewire(OutboxTable::class)
        ->assertCanSeeTableRecords([$unscheduled, $soonest, $later], inOrder: true);
});

it('lists sent emails newest first', function (): void {
    $this->travelTo(now()->startOfSecond());

    $oldest = makeOutboxEmail($this->user, $this->account, EmailStatus::SENT, ['sent_at' => now()->subHours(3)]);
    $newest = makeOutboxEmail($this->user, $this->account, EmailStatus::SENT, ['sent_at' => now()->subHour()]);
    $middle = makeOutboxEmail($this->user, $this->account, EmailStatus::SENT, ['sent_at' => now()->subHours(2)]);

    livewire(OutboxTable::class)
        ->call('setStatusTab', 'sent')
        ->assertCanSeeTableRecords([$newest, $middle, $oldest], inOrder: true);
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
        ->call('setStatusTab', 'failed')
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
        ->assertNotified()
        ->assertDispatched('outbox:changed');

    expect($email->refresh()->scheduled_for?->timestamp)->toBe($target->timestamp);
});

it('retry row action re-queues a failed email', function (): void {
    $email = makeOutboxEmail($this->user, $this->account, EmailStatus::FAILED, [
        'last_error' => 'timeout',
        'attempts' => 3,
    ]);

    livewire(OutboxTable::class)
        ->call('setStatusTab', 'failed')
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
