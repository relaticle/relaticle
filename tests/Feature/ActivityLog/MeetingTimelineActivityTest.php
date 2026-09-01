<?php

declare(strict_types=1);

use App\ActivityLog\MeetingEventPalette;
use App\ActivityLog\MeetingEventRenderer;
use Carbon\CarbonImmutable;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

mutates(MeetingEventRenderer::class);
mutates(MeetingEventPalette::class);

it('renders meeting.created with the defined activity-log label, not a dotted lookup miss', function (): void {
    $html = app(MeetingEventRenderer::class)->render(new TimelineEntry(
        id: 'meeting-created',
        type: 'activity_log',
        event: 'meeting.created',
        occurredAt: CarbonImmutable::now(),
        dedupKey: 'meeting-created',
        sourcePriority: 0,
    ))->render();

    expect($html)
        ->toContain(__('activity-log.events')['meeting.created']['label'])
        ->not->toContain('activity-log.events.meeting.created.label');
});

it('renders meeting.cancelled with the defined activity-log label, not a dotted lookup miss', function (): void {
    $html = app(MeetingEventRenderer::class)->render(new TimelineEntry(
        id: 'meeting-cancelled',
        type: 'activity_log',
        event: 'meeting.cancelled',
        occurredAt: CarbonImmutable::now(),
        dedupKey: 'meeting-cancelled',
        sourcePriority: 0,
    ))->render();

    expect($html)
        ->toContain(__('activity-log.events')['meeting.cancelled']['label'])
        ->not->toContain('activity-log.events.meeting.cancelled.label');
});
