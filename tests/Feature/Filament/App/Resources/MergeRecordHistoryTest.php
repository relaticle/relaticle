<?php

declare(strict_types=1);

use App\Actions\Merge\MergeRecords;
use App\Enums\CreationSource;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(MergeRecords::class);

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
