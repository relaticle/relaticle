<?php

declare(strict_types=1);

use App\Events\RecordMerged;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\EmailIntegration\EmailIntegrationServiceProvider;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Listeners\MoveMergedRecordCommunicationListener;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;

mutates(MoveMergedRecordCommunicationListener::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());
    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->getKey(),
        'user_id' => $this->user->getKey(),
    ]));
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

function communicationRecord(string $type, array $attributes = []): Model
{
    $factory = $type === 'company' ? Company::factory() : People::factory();

    return $factory->recycle([test()->user, test()->workspace])->create($attributes);
}

function mergeCommunication(string $type, Model $kept, Model $duplicate): void
{
    $page = $type === 'company' ? ViewCompany::class : ViewPeople::class;

    livewire($page, ['record' => $kept->getKey()])
        ->callAction(
            TestAction::make('mergeRecord')->schemaComponent('recordActions', schema: 'infolist'),
            data: ['duplicate_id' => $duplicate->getKey(), 'keep' => 'current'],
        )
        ->assertHasNoActionErrors();
}

function communicationEmail(array $attributes = [], string $state = 'inbound'): Email
{
    return Email::factory()->{$state}()->create([
        'workspace_id' => test()->workspace->getKey(),
        'user_id' => test()->user->getKey(),
        'connected_account_id' => test()->account->getKey(),
        ...$attributes,
    ]);
}

function communicationMeeting(string $startsAt): Meeting
{
    return Meeting::factory()->create([
        'workspace_id' => test()->workspace->getKey(),
        'connected_account_id' => test()->account->getKey(),
        'organizer_email' => 'organizer@outside-vendor.io',
        'starts_at' => $startsAt,
        'ends_at' => date('Y-m-d H:i:s', strtotime($startsAt) + 1800),
    ]);
}

function linkedIds(string $table, string $itemColumn, string $morph, Model $record): array
{
    return DB::table($table)
        ->where(Str::singular($table).'_type', $morph)
        ->where(Str::singular($table).'_id', $record->getKey())
        ->pluck($itemColumn)
        ->sort()
        ->values()
        ->all();
}

it('moves emails, meetings, participants and attendees and keeps a shared link once', function (string $type): void {
    $morph = $type;
    $contactColumn = $type === 'company' ? 'company_id' : 'contact_id';
    $kept = communicationRecord($type);
    $duplicate = communicationRecord($type);
    $onlyKept = communicationEmail();
    $onlyDuplicate = communicationEmail();
    $shared = communicationEmail();
    $kept->emails()->attach([$onlyKept->getKey(), $shared->getKey()]);
    $duplicate->emails()->attach([$onlyDuplicate->getKey(), $shared->getKey()]);

    $sharedMeeting = communicationMeeting('2026-02-01 10:00:00');
    $duplicateMeeting = communicationMeeting('2026-03-01 10:00:00');
    foreach ([$kept, $duplicate] as $record) {
        DB::table('meetingables')->insert(['meeting_id' => $sharedMeeting->getKey(), 'meetingable_type' => $morph, 'meetingable_id' => $record->getKey(), 'link_source' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('meetingables')->insert(['meeting_id' => $duplicateMeeting->getKey(), 'meetingable_type' => $morph, 'meetingable_id' => $duplicate->getKey(), 'link_source' => 'auto', 'created_at' => now(), 'updated_at' => now()]);

    $participant = EmailParticipant::factory()->create(['email_id' => $onlyDuplicate->getKey(), 'role' => EmailParticipantRole::FROM, $contactColumn => $duplicate->getKey()]);
    $attendee = MeetingAttendee::factory()->create(['meeting_id' => $duplicateMeeting->getKey(), $contactColumn => $duplicate->getKey()]);

    mergeCommunication($type, $kept, $duplicate);

    expect(linkedIds('emailables', 'email_id', $morph, $kept))->toBe(collect([$onlyKept, $onlyDuplicate, $shared])->map->getKey()->sort()->values()->all())
        ->and(linkedIds('emailables', 'email_id', $morph, $duplicate))->toBe([])
        ->and(linkedIds('meetingables', 'meeting_id', $morph, $kept))->toBe(collect([$sharedMeeting, $duplicateMeeting])->map->getKey()->sort()->values()->all())
        ->and(linkedIds('meetingables', 'meeting_id', $morph, $duplicate))->toBe([])
        ->and($participant->refresh()->{$contactColumn})->toBe($kept->getKey())
        ->and($attendee->refresh()->{$contactColumn})->toBe($kept->getKey());
})->with(['company', 'people']);

it('adds up the counters without counting a shared email or meeting twice', function (string $type): void {
    $kept = communicationRecord($type, [
        'email_count' => 3, 'inbound_email_count' => 2, 'outbound_email_count' => 1,
        'meeting_count' => 2, 'last_email_at' => '2026-01-10 09:00:00',
        'last_meeting_at' => '2026-02-01 10:00:00', 'last_interaction_at' => '2026-02-01 10:00:00',
    ]);
    $duplicate = communicationRecord($type, [
        'email_count' => 2, 'inbound_email_count' => 1, 'outbound_email_count' => 1,
        'meeting_count' => 1, 'last_email_at' => '2026-03-05 09:00:00',
        'last_meeting_at' => '2026-01-01 10:00:00', 'last_interaction_at' => '2026-03-05 09:00:00',
    ]);
    $sharedInbound = communicationEmail();
    $sharedInternal = communicationEmail(['is_internal' => true]);
    $sharedOutbound = communicationEmail(state: 'outbound');
    foreach ([$sharedInbound, $sharedInternal, $sharedOutbound] as $email) {
        $kept->emails()->attach($email->getKey());
        $duplicate->emails()->attach($email->getKey());
    }
    $sharedMeeting = communicationMeeting('2026-02-01 10:00:00');
    foreach ([$kept, $duplicate] as $record) {
        DB::table('meetingables')->insert(['meeting_id' => $sharedMeeting->getKey(), 'meetingable_type' => $type, 'meetingable_id' => $record->getKey(), 'link_source' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
    }

    mergeCommunication($type, $kept, $duplicate);

    $row = DB::table($kept->getTable())->where('id', $kept->getKey())->first();

    expect([$row->email_count, $row->inbound_email_count, $row->outbound_email_count, $row->meeting_count])->toBe([3, 2, 1, 2])
        ->and([$row->last_email_at, $row->last_meeting_at, $row->last_interaction_at])->toBe(['2026-03-05 09:00:00', '2026-02-01 10:00:00', '2026-03-05 09:00:00']);
})->with(['company', 'people']);

it('keeps the kept record\'s dates when the duplicate has none', function (): void {
    $kept = communicationRecord('company', ['email_count' => 1, 'inbound_email_count' => 1, 'last_email_at' => '2026-01-10 09:00:00']);
    $duplicate = communicationRecord('company');

    mergeCommunication('company', $kept, $duplicate);

    expect($kept->fresh()->email_count)->toBe(1)
        ->and($kept->fresh()->last_email_at->toDateTimeString())->toBe('2026-01-10 09:00:00')
        ->and($kept->fresh()->last_meeting_at)->toBeNull();
});

it('moves the communication of merged records while the email feature is off', function (string $type): void {
    config()->set('relaticle.features.email_integration', false);
    Feature::flushCache();
    Event::forget(RecordMerged::class);
    (new EmailIntegrationServiceProvider(app()))->boot();
    $kept = communicationRecord($type);
    $duplicate = communicationRecord($type);
    $email = communicationEmail();
    $duplicate->emails()->attach($email->getKey());

    mergeCommunication($type, $kept, $duplicate);

    expect(linkedIds('emailables', 'email_id', $type, $kept))->toBe([$email->getKey()])
        ->and(linkedIds('emailables', 'email_id', $type, $duplicate))->toBe([]);
})->with(['company', 'people']);

it('never lets a combined counter go below zero', function (string $type): void {
    $kept = communicationRecord($type, ['email_count' => 0, 'inbound_email_count' => 0, 'meeting_count' => 0]);
    $duplicate = communicationRecord($type, ['email_count' => 0, 'inbound_email_count' => 0, 'meeting_count' => 0]);
    $email = communicationEmail();
    $kept->emails()->attach($email->getKey());
    $duplicate->emails()->attach($email->getKey());
    $meeting = communicationMeeting('2026-02-01 10:00:00');
    foreach ([$kept, $duplicate] as $record) {
        DB::table('meetingables')->insert(['meeting_id' => $meeting->getKey(), 'meetingable_type' => $type, 'meetingable_id' => $record->getKey(), 'link_source' => 'auto', 'created_at' => now(), 'updated_at' => now()]);
    }

    mergeCommunication($type, $kept, $duplicate);

    $row = DB::table($kept->getTable())->where('id', $kept->getKey())->first();

    expect([$row->email_count, $row->inbound_email_count, $row->outbound_email_count, $row->meeting_count])->toBe([0, 0, 0, 0]);
})->with(['company', 'people']);
