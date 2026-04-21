<?php

declare(strict_types=1);

use App\Models\Company;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
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
