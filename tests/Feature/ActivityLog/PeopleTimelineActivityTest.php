<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Relaticle\ActivityLog\Filament\Livewire\ActivityLogLivewire;
use Relaticle\ActivityLog\Support\ActivityLogSummary;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

mutates(ActivityLogLivewire::class);
mutates(ActivityLogSummary::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create(['name' => 'John']);
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('loads causer eagerly when resolving activity_log entries so strict lazy-loading does not throw', function (): void {
    $person = People::factory()->create([
        'name' => 'Alpha',
        'team_id' => $this->team->getKey(),
    ]);
    $person->update(['name' => 'Bravo']);

    $entries = TimelineBuilder::make($person)
        ->fromActivityLog()
        ->get();

    expect($entries->where('type', 'activity_log')->count())->toBeGreaterThanOrEqual(1);
});

it('renders activity_log entries in the ActivityLogLivewire UI when fromActivityLog() is chained', function (): void {
    $person = People::factory()->create([
        'name' => 'Alpha',
        'team_id' => $this->team->getKey(),
    ]);
    $person->update(['name' => 'Bravo']);

    livewire(ActivityLogLivewire::class, [
        'subjectClass' => $person::class,
        'subjectKey' => $person->getKey(),
        'perPage' => 20,
    ])->assertSeeHtml('data-type="activity_log"');
});

it('groups entries by ISO week with relative labels', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-04-19 12:00:00')); // Sunday of week containing 13 Apr

    $person = People::factory()->create([
        'name' => 'Alpha',
        'team_id' => $this->team->getKey(),
    ]);

    $this->travelTo(CarbonImmutable::parse('2026-04-15 09:00:00'));
    $person->update(['name' => 'Bravo']);

    $this->travelTo(CarbonImmutable::parse('2026-04-08 09:00:00'));
    $person->update(['name' => 'Charlie']);

    $this->travelTo(CarbonImmutable::parse('2026-03-25 09:00:00'));
    $person->update(['name' => 'Delta']);

    $this->travelTo(CarbonImmutable::parse('2026-04-19 12:00:00'));

    livewire(ActivityLogLivewire::class, [
        'subjectClass' => $person::class,
        'subjectKey' => $person->getKey(),
        'perPage' => 20,
        'groupByDate' => true,
    ])
        ->assertSee('This week')
        ->assertSee('Last week')
        ->assertSee('Week of Mar 23');
});

it('renders a concise summary sentence with changed field labels', function (): void {
    $person = People::factory()->create([
        'name' => 'Alpha',
        'team_id' => $this->team->getKey(),
    ]);
    $person->update(['name' => 'Bravo']);

    livewire(ActivityLogLivewire::class, [
        'subjectClass' => $person::class,
        'subjectKey' => $person->getKey(),
        'perPage' => 20,
    ])
        ->assertSeeText('Name')
        ->assertSeeText('changed');
});

it('renders old and new values inside the collapsed diff panel markup', function (): void {
    $person = People::factory()->create([
        'name' => 'Alpha',
        'team_id' => $this->team->getKey(),
    ]);
    $person->update(['name' => 'Bravo']);

    livewire(ActivityLogLivewire::class, [
        'subjectClass' => $person::class,
        'subjectKey' => $person->getKey(),
        'perPage' => 20,
    ])
        ->assertSeeText('Alpha')
        ->assertSeeText('Bravo');
});

it('does not render a chevron for created entries', function (): void {
    $person = People::factory()->create([
        'name' => 'Alpha',
        'team_id' => $this->team->getKey(),
    ]);

    $html = livewire(ActivityLogLivewire::class, [
        'subjectClass' => $person::class,
        'subjectKey' => $person->getKey(),
        'perPage' => 20,
    ])->html();

    $createdBlockStart = strpos($html, 'data-event="created"');
    expect($createdBlockStart)->not->toBeFalse();

    $tail = substr($html, (int) $createdBlockStart, 2000);
    expect($tail)->not->toContain('remix-arrow-down-s-line');
});

describe('ActivityLogSummary::from()', function (): void {
    it('builds a one-field sentence', function (): void {
        $summary = ActivityLogSummary::from(sampleUpdatedEntry(['name' => 'New']));
        expect($summary->summarySentence)->toBe('System changed Name');
    });

    it('builds a two-field sentence', function (): void {
        $summary = ActivityLogSummary::from(sampleUpdatedEntry([
            'name' => 'New',
            'title' => 'CTO',
        ]));
        expect($summary->summarySentence)->toBe('System changed 2 attributes');
    });

    it('builds an N-field sentence with overflow', function (): void {
        $summary = ActivityLogSummary::from(sampleUpdatedEntry([
            'name' => 'New',
            'title' => 'CTO',
            'email' => 'x@y.com',
            'phone' => '123',
        ]));
        expect($summary->summarySentence)->toBe('System changed 4 attributes');
    });

    it('filters out internal timestamp columns', function (): void {
        $summary = ActivityLogSummary::from(sampleUpdatedEntry([
            'name' => 'New',
            'updated_at' => '2026-04-19 12:00:00',
        ]));
        expect($summary->changedFieldLabels)->toBe(['Name']);
    });
});

function sampleUpdatedEntry(array $attributes): TimelineEntry
{
    return new TimelineEntry(
        id: 'test-1',
        type: 'activity_log',
        event: 'updated',
        occurredAt: CarbonImmutable::now(),
        dedupKey: 'test-1',
        sourcePriority: 0,
        properties: [
            'attributes' => $attributes,
            'old' => array_fill_keys(array_keys($attributes), 'old'),
        ],
    );
}
