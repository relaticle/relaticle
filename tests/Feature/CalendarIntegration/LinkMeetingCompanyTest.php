<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\CustomField;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;

mutates(LinkMeetingAction::class);

it('auto-links a meeting to a company by attendee email domain', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create(['auto_create_companies' => true]));
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'person@acme.com',
        'response_status' => AttendeeResponseStatus::ACCEPTED,
        'is_self' => false,
    ]);

    (app(LinkMeetingAction::class))->execute($meeting->fresh());

    $company = Company::query()->where('team_id', $account->team_id)->first();
    expect($company)->not->toBeNull();
    expect($meeting->companies()->count())->toBe(1);
});

it('skips company creation for public domains', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create(['auto_create_companies' => true]));
    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'user@gmail.com',
        'is_self' => false,
    ]);

    (app(LinkMeetingAction::class))->execute($meeting->fresh());

    expect(Company::query()->where('team_id', $account->team_id)->count())->toBe(0);
});

it('does not downgrade an existing manual company link to auto', function (): void {
    $user = User::factory()->withTeam()->create();
    $this->actingAs($user);
    $team = $user->currentTeam;
    Filament::setTenant($team);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'auto_create_companies' => false,
    ]));

    $domainsField = CustomField::query()
        ->where('tenant_id', $account->team_id)
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->first();

    if (! $domainsField) {
        $this->markTestSkipped('No domains custom field seeded for this team.');
    }

    // A company that owns the attendee's domain, already manually linked to the meeting.
    $company = Company::factory()->create(['team_id' => $account->team_id, 'name' => 'Acme']);
    $company->saveCustomFieldValue($domainsField, 'https://acme.com', $company->team);

    $meeting = Meeting::factory()->create([
        'team_id' => $account->team_id,
        'connected_account_id' => $account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'person@acme.com',
        'is_self' => false,
    ]);

    (app(LinkMeetingToRecordAction::class))->execute($meeting, $company);
    expect($meeting->companies()->first()?->pivot->link_source)->toBe('manual');

    (app(LinkMeetingAction::class))->execute($meeting->fresh());

    // The auto-link pass must not flip the prior manual pivot to 'auto'.
    expect($meeting->companies()->count())->toBe(1);
    expect($meeting->companies()->first()?->pivot->link_source)->toBe('manual');
});
