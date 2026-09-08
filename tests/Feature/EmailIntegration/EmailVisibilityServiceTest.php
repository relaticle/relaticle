<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use App\Models\TeamInvitation;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\ConnectionStrength;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(EmailVisibilityService::class, VisibleMeetingScope::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create([
        'email' => 'owner@thefireflytech.com',
    ]);
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;

    $this->service = app(EmailVisibilityService::class);
});

it('infers workspace domains from member emails and connected accounts', function (): void {
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'sales@thefireflytech.com',
    ]));

    expect($this->service->workspaceDomains($this->team))->toBe(['thefireflytech.com']);
});

it('ignores consumer email domains when inferring workspace domains', function (): void {
    $this->user->update(['email' => 'owner@gmail.com']);

    expect($this->service->workspaceDomains($this->team))->toBe([]);
});

it('includes system default visibility rows for members and workspace domains', function (): void {
    $rows = $this->service->visibilityTableRows($this->team, collect());

    expect($rows)->toHaveCount(2)
        ->and($rows[0]['address'])->toBe(__('filament/pages/email-privacy-settings.visibility.table.members_row'))
        ->and($rows[1]['address'])->toBe('thefireflytech.com');
});

it('treats connected mailbox addresses as protected member emails', function (): void {
    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'whitesacks.dev@gmail.com',
    ]));

    expect($this->service->memberEmailsForTeam($this->team))->toContain('whitesacks.dev@gmail.com');
});

it('treats pending invitee emails as protected member emails', function (): void {
    TeamInvitation::factory()->create([
        'team_id' => $this->team->id,
        'email' => 'pending@thefireflytech.com',
    ]);

    expect($this->service->memberEmailsForTeam($this->team))->toContain('pending@thefireflytech.com');
});

it('normalizes domain input before matching visibility rules', function (): void {
    expect($this->service->normalizeDomainInput('https://mail.outskill.com/path'))
        ->toBe('mail.outskill.com');
});

it('hides custom visibility rows that duplicate inferred workspace domains', function (): void {
    $this->user->update(['email' => 'owner@outskill.com']);

    TeamEmailBlocklist::factory()->protected()->domain('outskill.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $rows = $this->service->visibilityTableRows(
        $this->team,
        TeamEmailBlocklist::query()->where('team_id', $this->team->id)->get(),
    );

    expect(collect($rows)->where('address', 'outskill.com')->count())->toBe(1)
        ->and(collect($rows)->firstWhere('address', 'outskill.com')['is_system'])->toBeTrue()
        ->and(collect($rows)->firstWhere('address', 'outskill.com')['enforcement_value'])->toBe('protected');
});

it('prefers blocked over protected when resolving record mailbox copy', function (): void {
    $person = People::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->user->id,
    ]);

    TeamEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->user->id,
    ]);

    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $person->team_id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $person->saveCustomFieldValue($emailsField, ['blocked@contact.com'], $person->team);

    expect($this->service->recordMailboxHiddenEnforcement($person))
        ->toBe(EmailVisibilityEnforcement::Blocked)
        ->and($this->service->recordMailboxHiddenCopy($person)['description'])
        ->toBe(__('filament/pages/record-emails.blocked.description'));
});

it('suppresses record creation for workspace member emails', function (): void {
    expect($this->service->suppressesRecordCreation(
        'owner@thefireflytech.com',
        $this->team->getKey(),
        null,
    ))->toBeTrue();

    expect($this->service->suppressesRecordCreation(
        'external@partner.com',
        $this->team->getKey(),
        null,
    ))->toBeFalse();
});

it('scopes communication intelligence metrics to mail the viewer can see', function (): void {
    $person = People::factory()->for($this->team)->create([
        'email_count' => 99,
        'inbound_email_count' => 50,
        'outbound_email_count' => 49,
    ]);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $visible = Email::factory()->inbound()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
    ]);
    $person->emails()->attach($visible->getKey());

    $coworker = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($coworker, ['role' => 'editor']);

    $coworkerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $coworker->id,
    ]));

    $private = Email::factory()->private()->outbound()->create([
        'team_id' => $this->team->id,
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

it('computes calendar intelligence without an ambiguous team_id join', function (): void {
    $person = People::factory()->for($this->team)->create();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $startsAt = now()->addDay();

    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
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
    $person = People::factory()->for($this->team)->create();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $coworker = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($coworker, ['role' => 'editor']);

    $coworkerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $coworker->id,
    ]));

    $messageId = '<preferred-metrics@example.com>';
    $preferredSentAt = now()->subHour();

    $preferred = Email::factory()->inbound()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
        'rfc_message_id' => $messageId,
        'sent_at' => $preferredSentAt,
        'is_internal' => false,
    ]);
    $duplicate = Email::factory()->outbound()->create([
        'team_id' => $this->team->id,
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
    $person = People::factory()->for($this->team)->create();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $coworker = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($coworker, ['role' => 'editor']);

    $coworkerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $coworker->id,
    ]));

    $messageId = '<workspace-sent@example.com>';

    $ownerInbound = Email::factory()->inbound()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
        'rfc_message_id' => $messageId,
        'is_internal' => false,
    ]);
    $coworkerOutbound = Email::factory()->outbound()->create([
        'team_id' => $this->team->id,
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

it('scores connection strength on one preferred copy of a duplicated rfc message', function (): void {
    $this->travelTo('2026-09-08 12:00:00');

    $person = People::factory()->for($this->team)->create();

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $coworker = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($coworker, ['role' => 'editor']);

    $coworkerAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $coworker->id,
    ]));

    $messageId = '<connection-copy@example.com>';
    $recentSentAt = now()->subDay();

    $preferred = Email::factory()->inbound()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
        'rfc_message_id' => $messageId,
        'sent_at' => $recentSentAt,
        'is_internal' => false,
    ]);
    $duplicate = Email::factory()->outbound()->create([
        'team_id' => $this->team->id,
        'user_id' => $coworker->id,
        'connected_account_id' => $coworkerAccount->getKey(),
        'rfc_message_id' => $messageId,
        'sent_at' => $recentSentAt,
        'is_internal' => false,
    ]);
    $staleCoworkerOnly = Email::factory()->outbound()->create([
        'team_id' => $this->team->id,
        'user_id' => $coworker->id,
        'connected_account_id' => $coworkerAccount->getKey(),
        'rfc_message_id' => '<coworker-only@example.com>',
        'sent_at' => now()->subDays(200),
        'is_internal' => false,
    ]);

    $person->emails()->attach([$preferred->getKey(), $duplicate->getKey(), $staleCoworkerOnly->getKey()]);

    $metrics = $this->service->visibleCommunicationIntelligence($person, $this->user);

    expect($metrics->emailCount)->toBe(2)
        ->and($metrics->connectionStrength)->toBe(ConnectionStrength::Weak)
        ->and($metrics->strongestConnectionName)->toBe($this->user->name);
});
