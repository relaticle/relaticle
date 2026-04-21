<?php

declare(strict_types=1);

use App\Models\Activity;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Observers\MeetingObserver;

mutates(MeetingObserver::class);

beforeEach(function (): void {
    Queue::fake();
});

it('logs a meeting.created activity entry when a meeting is created', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());

    $meeting = Meeting::factory()->for($account, 'connectedAccount')->create();

    $exists = Activity::withoutGlobalScopes()
        ->where('subject_type', $meeting->getMorphClass())
        ->where('subject_id', $meeting->getKey())
        ->where('event', 'meeting.created')
        ->exists();

    expect($exists)->toBeTrue();
});

it('logs a meeting.cancelled activity entry when a meeting is deleted', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());

    $meeting = Meeting::withoutEvents(fn () => Meeting::factory()->for($account, 'connectedAccount')->create());

    $meeting->delete();

    $exists = Activity::withoutGlobalScopes()
        ->where('subject_type', $meeting->getMorphClass())
        ->where('subject_id', $meeting->getKey())
        ->where('event', 'meeting.cancelled')
        ->exists();

    expect($exists)->toBeTrue();
});

it('records title in properties when a meeting is created', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());

    $meeting = Meeting::factory()->for($account, 'connectedAccount')->create(['title' => 'Q2 Kickoff']);

    $activity = Activity::withoutGlobalScopes()
        ->where('subject_type', $meeting->getMorphClass())
        ->where('subject_id', $meeting->getKey())
        ->where('event', 'meeting.created')
        ->firstOrFail();

    expect($activity->properties->get('title'))->toBe('Q2 Kickoff');
});

it('does not log meeting.created when events are suppressed', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create());

    Meeting::withoutEvents(fn () => Meeting::factory()->for($account, 'connectedAccount')->create());

    expect(
        Activity::withoutGlobalScopes()
            ->where('event', 'meeting.created')
            ->exists()
    )->toBeFalse();
});
