<?php

declare(strict_types=1);

use App\Actions\Merge\MergeRecords;
use App\Actions\Merge\MoveRecordHistory;
use App\ActivityLog\AppEventPalette;
use App\Enums\CreationSource;
use App\Filament\Pages\Workspace\ActivityLog;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\ActivityLog\Filament\Livewire\ActivityLogLivewire;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(MergeRecords::class, MoveRecordHistory::class, AppEventPalette::class, ActivityLog::class);

beforeEach(function (): void {
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

/**
 * @return array<string, array{0: class-string, 1: Closure}>
 */
function mergeHistoryEntities(): array
{
    return [
        'company' => [ViewCompany::class, fn (Workspace $workspace, User $owner, array $attributes = []): Company|People => mergeCompanyRecord($workspace, $owner, $attributes)],
        'person' => [ViewPeople::class, fn (Workspace $workspace, User $owner, array $attributes = []): Company|People => mergePersonRecord($workspace, $owner, $attributes)],
    ];
}

/**
 * @param  class-string  $page
 */
function mergeHistoryRun(string $page, Company|People $current, Company|People $picked, bool $keepPicked): Company|People
{
    livewire($page, ['record' => $current->getKey()])
        ->callAction(mergeRecordAction(), data: ['picked_id' => $picked->getKey(), 'keep_picked' => $keepPicked]);

    return mergedRecord();
}

it('gives the new record the creation date of the record the merge continues', function (): void {
    foreach (mergeHistoryEntities() as [$page, $make]) {
        foreach (['older' => '2026-01-10 09:00:00', 'newer' => '2026-03-20 15:30:00'] as $rightHand => $expected) {
            $this->travelTo('2026-01-10 09:00:00');
            $older = $make($this->workspace, $this->user);
            $this->travelTo('2026-03-20 15:30:00');
            $newer = $make($this->workspace, $this->user);
            $this->travelTo('2026-04-01 12:00:00');

            $merged = $rightHand === 'older'
                ? mergeHistoryRun($page, $newer, $older, true)
                : mergeHistoryRun($page, $older, $newer, true);

            expect($merged->created_at->toDateTimeString())->toBe($expected);
        }
    }
});

it('takes the creation date, creator and source from the other record when the priority one is sample data', function (): void {
    $creator = User::factory()->create();

    foreach (mergeHistoryEntities() as [$page, $make]) {
        $this->travelTo('2026-01-10 09:00:00');
        $sample = $make($this->workspace, $this->user, ['creation_source' => CreationSource::SAMPLE]);
        $this->travelTo('2026-03-20 15:30:00');
        $real = $make($this->workspace, $this->user, ['creation_source' => CreationSource::MCP, 'creator_id' => $creator->getKey()]);
        $this->travelTo('2026-04-01 12:00:00');

        $merged = mergeHistoryRun($page, $sample, $real, false);

        expect($merged->created_at->toDateTimeString())->toBe('2026-03-20 15:30:00')
            ->and($merged->creator_id)->toBe($creator->getKey())
            ->and($merged->creation_source)->toBe(CreationSource::MCP);
    }
});

/**
 * @return list<string>
 */
function mergeHistoryEvents(Company|People $record): array
{
    return Activity::query()->where('subject_id', $record->getKey())->orderBy('id')->pluck('event')->all();
}

it('gives the new record both originals\' later changes and exactly one created entry, the origin\'s', function (): void {
    foreach (mergeHistoryEntities() as [$page, $make]) {
        $kept = $make($this->workspace, $this->user, ['name' => 'Brightline Health']);
        $duplicate = $make($this->workspace, $this->user, ['name' => 'Brightline Health Inc.']);
        $keptCreated = Activity::query()->where('subject_id', $kept->getKey())->where('event', 'created')->value('id');
        $kept->update(['name' => 'Brightline']);
        $duplicate->update(['name' => 'Brightline Health Inc']);

        $merged = mergeHistoryRun($page, $kept, $duplicate, false);

        $events = mergeHistoryEvents($merged);
        expect(array_count_values($events))->toBe(['created' => 1, 'updated' => 2, 'record_merged' => 1])
            ->and(Activity::query()->where('subject_id', $merged->getKey())->where('event', 'created')->value('id'))->toBe($keptCreated)
            ->and(mergeHistoryEvents($kept))->toBe([])
            ->and(mergeHistoryEvents($duplicate))->toBe(['created']);
    }
});

it('moves the creation row of the other record when the priority one is sample data', function (): void {
    foreach (mergeHistoryEntities() as [$page, $make]) {
        $sample = $make($this->workspace, $this->user, ['creation_source' => CreationSource::SAMPLE]);
        $real = $make($this->workspace, $this->user, ['creation_source' => CreationSource::MCP]);
        $realCreated = Activity::query()->where('subject_id', $real->getKey())->where('event', 'created')->value('id');

        $merged = mergeHistoryRun($page, $sample, $real, false);

        expect(Activity::query()->where('subject_id', $merged->getKey())->where('event', 'created')->pluck('id')->all())->toBe([$realCreated])
            ->and(mergeHistoryEvents($sample))->toBe(['created']);
    }
});

it('shows the other record\'s creation-time attribute values as one change at its creation time', function (): void {
    foreach ([[ViewCompany::class, 'mergeCompanyRecord', 'company'], [ViewPeople::class, 'mergePersonRecord', 'people']] as [$page, $make, $type]) {
        $field = $type === 'company' ? 'icp' : 'emails';
        $value = $type === 'company' ? true : ['dana@brightline.com'];
        $this->travelTo('2026-01-10 09:00:00');
        $kept = $make($this->workspace, $this->user);
        $this->travelTo('2026-03-20 15:30:00');
        $duplicate = $make($this->workspace, $this->user, [], [$field => $value]);
        $this->travelTo('2026-04-01 12:00:00');

        $merged = mergeHistoryRun($page, $kept, $duplicate, false);

        $moved = Activity::query()->where('subject_id', $merged->getKey())->where('event', 'custom_field_changes')->get();
        $html = livewire(ActivityLogLivewire::class, ['subjectClass' => $merged::class, 'subjectKey' => $merged->getKey()])->html();
        expect($moved)->toHaveCount(1)
            ->and($moved->first()->created_at->toDateTimeString())->toBe('2026-03-20 15:30:00')
            ->and(substr_count($html, 'data-event="created"'))->toBe(1)
            ->and(Activity::query()->where('subject_id', $duplicate->getKey())->where('event', 'created')->exists())->toBeTrue();
    }
});

it('keeps two originals\' rows from one batch as separate entries', function (): void {
    foreach (mergeHistoryEntities() as [$page, $make]) {
        $kept = $make($this->workspace, $this->user, ['name' => 'Brightline Health']);
        $duplicate = $make($this->workspace, $this->user, ['name' => 'Brightline Health Inc.']);
        $kept->update(['name' => 'Brightline']);
        $duplicate->update(['name' => 'Brightline Health Inc']);
        $batch = (string) Str::uuid();
        Activity::query()->whereIn('subject_id', [$kept->getKey(), $duplicate->getKey()])->update(['batch_uuid' => $batch]);

        $merged = mergeHistoryRun($page, $kept, $duplicate, false);

        $batches = Activity::query()->where('subject_id', $merged->getKey())->where('event', '!=', 'record_merged')->pluck('batch_uuid');
        expect($batches)->toHaveCount(3)
            ->and($batches->unique())->toHaveCount(2)
            ->and($batches->contains($batch))->toBeFalse();
    }
});

it('shows only the latest merge after a chain of merges', function (): void {
    foreach (mergeHistoryEntities() as [$page, $make]) {
        $first = $make($this->workspace, $this->user, ['name' => 'Brightline Health']);
        $second = $make($this->workspace, $this->user, ['name' => 'Brightline Health Inc.']);
        $third = $make($this->workspace, $this->user, ['name' => 'Brightline Group']);

        $firstMerge = mergeHistoryRun($page, $first, $second, false);
        $secondMerge = mergeHistoryRun($page, $firstMerge, $third, false);

        $merges = Activity::query()->where('subject_id', $secondMerge->getKey())->where('event', 'record_merged')->get();
        expect($merges)->toHaveCount(1)
            ->and($merges->first()->properties['merged_names'])->toBe(['Brightline Health', 'Brightline Group'])
            ->and(mergeHistoryEvents($firstMerge))->toBe(['record_merged'])
            ->and(array_count_values(mergeHistoryEvents($secondMerge)))->toBe(['created' => 1, 'record_merged' => 1]);
    }
});

it('names who merged the two records and stores both names, priority first', function (): void {
    foreach (mergeHistoryEntities() as [$page, $make]) {
        $kept = $make($this->workspace, $this->user, ['name' => 'Brightline Health']);
        $duplicate = $make($this->workspace, $this->user, ['name' => 'Brightline Health Inc.']);

        $merged = mergeHistoryRun($page, $kept, $duplicate, true);

        $entry = Activity::query()->where('subject_id', $merged->getKey())->where('event', 'record_merged')->sole();
        expect($entry->properties['merged_names'])->toBe(['Brightline Health Inc.', 'Brightline Health'])
            ->and($entry->causer_id)->toBe($this->user->getKey());

        livewire(ActivityLogLivewire::class, ['subjectClass' => $merged::class, 'subjectKey' => $merged->getKey()])
            ->assertSee(e($this->user->name.' merged 2 records into this record'), escape: false)
            ->assertSeeHtml('data-event="record_merged"');

        $entry->update(['causer_id' => null, 'causer_type' => null]);
        livewire(ActivityLogLivewire::class, ['subjectClass' => $merged::class, 'subjectKey' => $merged->getKey()])
            ->assertSee('2 records were merged into this record');
    }
});

it('shows the originals no merge entry and no deleted row from the merge', function (): void {
    foreach (mergeHistoryEntities() as [$page, $make]) {
        $kept = $make($this->workspace, $this->user);
        $duplicate = $make($this->workspace, $this->user);

        mergeHistoryRun($page, $kept, $duplicate, false);

        expect(Activity::query()->whereIn('subject_id', [$kept->getKey(), $duplicate->getKey()])->whereIn('event', ['record_merged', 'deleted'])->exists())->toBeFalse();
    }
});

it('lists the merge on the workspace activity page and filters by it', function (): void {
    foreach (mergeHistoryEntities() as [$page, $make]) {
        $merged = mergeHistoryRun($page, $make($this->workspace, $this->user), $make($this->workspace, $this->user), false);
        $entry = Activity::query()->where('subject_id', $merged->getKey())->where('event', 'record_merged')->sole();

        livewire(ActivityLog::class)
            ->filterTable('event', 'record_merged')
            ->assertCanSeeTableRecords([$entry])
            ->assertSee(AppEventPalette::RecordMerged->getLabel());
    }
});
