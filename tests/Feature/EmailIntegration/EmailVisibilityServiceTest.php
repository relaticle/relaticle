<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Carbon\CarbonImmutable;
use Relaticle\EmailIntegration\Enums\ConnectionStrength;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\PreferredEmailCopyService;
use Relaticle\EmailIntegration\Services\PrivacyService;

mutates(EmailVisibilityService::class, VisibleMeetingScope::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create([
        'email' => 'owner@thefireflytech.com',
    ]);
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;

    $this->service = app(EmailVisibilityService::class);
});

it('infers workspace domains from member emails and connected accounts', function (): void {
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'email_address' => 'sales@thefireflytech.com',
    ]));

    expect($this->service->workspaceDomains($this->workspace))->toBe(['thefireflytech.com']);
});

it('ignores consumer email domains when inferring workspace domains', function (): void {
    $this->user->update(['email' => 'owner@gmail.com']);

    expect($this->service->workspaceDomains($this->workspace))->toBe([]);
});

it('includes system default visibility rows for members and workspace domains', function (): void {
    $rows = $this->service->visibilityTableRows($this->workspace, collect());

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['address'])->toBe(__('filament/pages/email-privacy-settings.visibility.table.members_row'))
        ->and($rows[1]['address'])->toBe('thefireflytech.com');
});

it('treats connected mailbox addresses as protected member emails', function (): void {
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'email_address' => 'whitesacks.dev@gmail.com',
    ]));

    expect($this->service->memberEmailsForWorkspace($this->workspace))->toContain('whitesacks.dev@gmail.com');
});

it('treats pending invitee emails as protected member emails', function (): void {
    WorkspaceInvitation::factory()->create([
        'workspace_id' => $this->workspace->id,
        'email' => 'pending@thefireflytech.com',
    ]);

    expect($this->service->memberEmailsForWorkspace($this->workspace))->toContain('pending@thefireflytech.com');
});

it('normalizes domain input before matching visibility rules', function (): void {
    expect($this->service->normalizeDomainInput('https://mail.outskill.com/path'))
        ->toBe('mail.outskill.com');
});

it('hides custom visibility rows that duplicate inferred workspace domains', function (): void {
    $this->user->update(['email' => 'owner@outskill.com']);

    WorkspaceEmailBlocklist::factory()->protected()->domain('outskill.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    $rows = $this->service->visibilityTableRows(
        $this->workspace,
        WorkspaceEmailBlocklist::query()->where('workspace_id', $this->workspace->id)->get(),
    );

    expect(collect($rows)->where('address', 'outskill.com')->count())->toBe(1)
        ->and(collect($rows)->firstWhere('address', 'outskill.com')['is_system'])->toBeTrue()
        ->and(collect($rows)->firstWhere('address', 'outskill.com')['enforcement_value'])->toBe('protected');
});

it('shows an unknown source for a visibility row whose creator was deleted', function (): void {
    WorkspaceEmailBlocklist::factory()->protected()->domain('vendor-partner.test')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => null,
    ]);

    $rows = $this->service->visibilityTableRows(
        $this->workspace,
        WorkspaceEmailBlocklist::query()->where('workspace_id', $this->workspace->id)->get(),
    );

    expect(collect($rows)->firstWhere('address', 'vendor-partner.test')['source'])
        ->toBe('Unknown');
});

it('prefers blocked over protected when resolving record mailbox copy', function (): void {
    $person = People::factory()->create([
        'workspace_id' => $this->workspace->id,
        'creator_id' => $this->user->id,
    ]);

    WorkspaceEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->user->id,
    ]);

    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $person->workspace_id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $person->saveCustomFieldValue($emailsField, ['blocked@contact.com'], $person->workspace);

    expect($this->service->recordMailboxHiddenEnforcement($person))
        ->toBe(EmailVisibilityEnforcement::Blocked)
        ->and($this->service->recordMailboxHiddenCopy($person)['description'])
        ->toBe(__('filament/pages/record-emails.blocked.description'));
});

it('suppresses record creation for workspace member emails', function (): void {
    expect($this->service->suppressesRecordCreation(
        'owner@thefireflytech.com',
        $this->workspace->getKey(),
        null,
    ))->toBeTrue();

    expect($this->service->suppressesRecordCreation(
        'external@partner.com',
        $this->workspace->getKey(),
        null,
    ))->toBeFalse();
});

it('scopes communication intelligence metrics to mail the viewer can see', function (): void {
    $person = People::factory()->for($this->workspace)->create([
        'email_count' => 99,
        'inbound_email_count' => 50,
        'outbound_email_count' => 49,
    ]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $visible = Email::factory()->inbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
    ]);
    $person->emails()->attach($visible->getKey());

    $coworker = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($coworker, ['role' => 'member']);

    $coworkerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $coworker->id,
    ]));

    $private = Email::factory()->private()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $coworker->id,
        'connected_account_id' => $coworkerAccount->getKey(),
    ]);
    $person->emails()->attach($private->getKey());

    $metrics = $this->service->visibleCommunicationIntelligence($person, $this->user);

    expect($metrics->emailCount)->toBe(1)
        ->and($metrics->lastEmailAt)->not->toBeNull()
        ->and($metrics->firstEmailAt)->not->toBeNull()
        ->and($metrics->connectionStrength)->not->toBe(ConnectionStrength::None);
});

it('computes calendar intelligence without an ambiguous workspace_id join', function (): void {
    $person = People::factory()->for($this->workspace)->create();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $startsAt = now()->addDay();

    $meeting = Meeting::factory()->create([
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $account->getKey(),
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addHour(),
    ]);

    $person->meetings()->attach($meeting->getKey());

    $metrics = $this->service->visibleCommunicationIntelligence($person, $this->user);

    expect($metrics->nextMeetingAt?->toDateTimeString())->toBe($startsAt->toDateTimeString())
        ->and($metrics->lastMeetingAt?->toDateTimeString())->toBe($startsAt->toDateTimeString())
        ->and($metrics->strongestConnectionName)->toBe($this->user->name);
});

it('counts one preferred copy when the same rfc message is synced twice', function (): void {
    $person = People::factory()->for($this->workspace)->create();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $coworker = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($coworker, ['role' => 'member']);

    $coworkerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $coworker->id,
    ]));

    $messageId = '<preferred-metrics@example.com>';
    $preferredSentAt = now()->subHour();

    $preferred = Email::factory()->inbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
        'rfc_message_id' => $messageId,
        'sent_at' => $preferredSentAt,
        'is_internal' => false,
    ]);
    $duplicate = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $coworker->id,
        'connected_account_id' => $coworkerAccount->getKey(),
        'rfc_message_id' => $messageId,
        'sent_at' => now(),
        'is_internal' => false,
    ]);

    $person->emails()->attach([$preferred->getKey(), $duplicate->getKey()]);

    $metrics = $this->service->visibleCommunicationIntelligence($person, $this->user);

    expect($metrics->emailCount)->toBe(1)
        ->and($metrics->emailCount)->toBe($this->service->visibleEmailCount($person, $this->user))
        ->and($metrics->firstEmailAt?->toDateTimeString())->toBe($preferredSentAt->toDateTimeString())
        ->and($metrics->lastEmailAt?->toDateTimeString())->toBe($preferredSentAt->toDateTimeString());
});

it('counts one preferred copy for every viewer of the same rfc message', function (): void {
    $person = People::factory()->for($this->workspace)->create();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $coworker = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($coworker, ['role' => 'member']);

    $coworkerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $coworker->id,
    ]));

    $messageId = '<workspace-sent@example.com>';

    $ownerInbound = Email::factory()->inbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
        'rfc_message_id' => $messageId,
        'is_internal' => false,
    ]);
    $coworkerOutbound = Email::factory()->outbound()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $coworker->id,
        'connected_account_id' => $coworkerAccount->getKey(),
        'rfc_message_id' => $messageId,
        'is_internal' => false,
    ]);

    $person->emails()->attach([$ownerInbound->getKey(), $coworkerOutbound->getKey()]);

    $ownerMetrics = $this->service->visibleCommunicationIntelligence($person, $this->user);
    $coworkerMetrics = $this->service->visibleCommunicationIntelligence($person, $coworker);

    expect($ownerMetrics->emailCount)->toBe(1)
        ->and($coworkerMetrics->emailCount)->toBe(1);
});

function copyOfMessage(User $owner, Workspace $workspace, EmailPrivacyTier $tier, CarbonImmutable $sentAt): Email
{
    $mailbox = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]));

    return Email::factory()->inbound()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'connected_account_id' => $mailbox->getKey(),
        'rfc_message_id' => '<shared-thread@example.com>',
        'sent_at' => $sentAt,
        'is_internal' => false,
        'privacy_tier' => $tier,
    ]);
}

it('shows a teammate the most open copy of a message two members synced', function (): void {
    $alice = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach([$alice->id => ['role' => 'member'], $viewer->id => ['role' => 'member']]);
    $person = People::factory()->for($this->workspace)->create();

    $open = copyOfMessage($this->user, $this->workspace, EmailPrivacyTier::FULL, now()->subHour());
    $closed = copyOfMessage($alice, $this->workspace, EmailPrivacyTier::METADATA_ONLY, now());
    $person->emails()->attach([$open->getKey(), $closed->getKey()]);

    $emails = $person->emails();
    resolve(PreferredEmailCopyService::class)->restrictToVisiblePreferredCopies($emails->getQuery(), $viewer);
    $picked = $emails->get();

    expect($picked)->toHaveCount(1)
        ->and($picked->first()->getKey())->toBe($open->getKey())
        ->and(resolve(PrivacyService::class)->effectiveTier($picked->first(), $viewer))->toBe(EmailPrivacyTier::FULL);
});

it('still shows a member their own copy first', function (): void {
    $alice = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($alice, ['role' => 'member']);
    $person = People::factory()->for($this->workspace)->create();

    $open = copyOfMessage($this->user, $this->workspace, EmailPrivacyTier::FULL, now()->subHour());
    $own = copyOfMessage($alice, $this->workspace, EmailPrivacyTier::METADATA_ONLY, now());
    $person->emails()->attach([$open->getKey(), $own->getKey()]);

    $emails = $person->emails();
    resolve(PreferredEmailCopyService::class)->restrictToVisiblePreferredCopies($emails->getQuery(), $alice);
    $picked = $emails->get();

    expect($picked->first()->getKey())->toBe($own->getKey());
});

it('never shows a teammate a private copy while another copy is shared', function (): void {
    $alice = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $viewer = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach([$alice->id => ['role' => 'member'], $viewer->id => ['role' => 'member']]);
    $person = People::factory()->for($this->workspace)->create();

    $private = copyOfMessage($this->user, $this->workspace, EmailPrivacyTier::PRIVATE, now());
    $subject = copyOfMessage($alice, $this->workspace, EmailPrivacyTier::SUBJECT, now()->subHour());
    $person->emails()->attach([$private->getKey(), $subject->getKey()]);

    $emails = $person->emails();
    resolve(PreferredEmailCopyService::class)->restrictToVisiblePreferredCopies($emails->getQuery(), $viewer);
    $picked = $emails->get();

    expect($picked)->toHaveCount(1)
        ->and($picked->first()->getKey())->toBe($subject->getKey());
});
