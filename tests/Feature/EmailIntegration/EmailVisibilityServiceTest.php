<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use App\Models\TeamInvitation;
use App\Models\User;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(EmailVisibilityService::class);

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
