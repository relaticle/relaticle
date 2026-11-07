<?php

declare(strict_types=1);

use App\Filament\Actions\PreviewRecordAction;
use App\Filament\Components\Infolists\RecordDetails;
use App\Filament\Components\Infolists\RecordOverview;
use App\Filament\Components\Tables\RecordChipColumn;
use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\ListCompanies;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource;
use App\Filament\Resources\PeopleResource\Pages\ListPeople;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(PreviewRecordAction::class, RecordChipColumn::class, RecordDetails::class, RecordOverview::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());
});

function previewHeader(string $html): string
{
    return Str::between($html, 'fi-record-preview-header', 'fi-record-embedded-page');
}

/**
 * @return array<int, string>
 */
function previewTabLabels(string $html): array
{
    preg_match_all('/fi-tabs-item-label">\s*([^<]+?)\s*</', $html, $matches);

    return $matches[1];
}

dataset('record lists', [
    'company' => [Company::class, ListCompanies::class, CompanyResource::class, ViewCompany::class],
    'person' => [People::class, ListPeople::class, PeopleResource::class, ViewPeople::class],
    'opportunity' => [Opportunity::class, ListOpportunities::class, OpportunityResource::class, ViewOpportunity::class],
]);

it('opens the :dataset record page in a preview without leaving the list', function (string $model, string $list, string $resource, string $page): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Northwind Traders']);

    livewire($list)
        ->assertSeeHtml('fi-record-preview-trigger')
        ->mountAction(TestAction::make('preview')->table($record))
        ->assertActionMounted(TestAction::make('preview')->table($record))
        ->assertMountedActionModalSeeHtml(['fi-record-embedded-page', 'wire:name="'.$page.'"'])
        ->assertMountedActionModalSee(['Northwind Traders', __('filament/record-page.overview.highlights')])
        ->assertMountedActionModalSeeHtml('href="'.$resource::getUrl('view', ['record' => $record]).'"');
})->with('record lists');

it('leaves the list heading in the topbar while a :dataset preview is open', function (string $model, string $list): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    livewire($list)
        ->mountAction(TestAction::make('preview')->table($record))
        ->assertMountedActionModalDontSeeHtml(['data-page-heading-source', 'fi-record-page"']);
})->with('record lists');

it('shows the :dataset record page tabs in the preview, overview first', function (string $model, string $list, string $resource, string $page, array $tabs): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    $html = livewire($list)
        ->mountAction(TestAction::make('preview')->table($record))
        ->getMountedActionModalHtml();

    expect(previewTabLabels($html))->toBe([__('filament/record-page.overview.label'), ...$tabs]);
})->with([
    'company' => [Company::class, ListCompanies::class, CompanyResource::class, ViewCompany::class, ['Activity', 'People', 'Tasks', 'Notes', 'Emails', 'Meetings']],
    'person' => [People::class, ListPeople::class, PeopleResource::class, ViewPeople::class, ['Activity', 'Tasks', 'Notes', 'Emails', 'Meetings']],
    'opportunity' => [Opportunity::class, ListOpportunities::class, OpportunityResource::class, ViewOpportunity::class, ['Activity', 'Tasks', 'Notes', 'Emails', 'Meetings']],
]);

it('places the previewed company within the list and links its neighbours', function (): void {
    [$oldest, $middle, $newest] = collect([3, 2, 1])
        ->map(fn (int $daysAgo): Company => Company::factory()->recycle([$this->user, $this->workspace])->create(['created_at' => now()->subDays($daysAgo)]))
        ->all();

    $header = previewHeader(livewire(ListCompanies::class)
        ->mountAction(TestAction::make('preview')->table($middle))
        ->getMountedActionModalHtml());

    expect($header)
        ->toContain(__('filament/record-page.preview.position', ['position' => 2, 'total' => 3, 'label' => 'Companies']))
        ->toContain($newest->getKey())
        ->toContain($oldest->getKey());
});

it('keeps counting from the position a step passes when the record is on another page', function (): void {
    [$oldest, $middle] = collect([3, 2, 1])
        ->map(fn (int $daysAgo): Company => Company::factory()->recycle([$this->user, $this->workspace])->create(['created_at' => now()->subDays($daysAgo)]))
        ->all();

    $header = previewHeader(livewire(ListCompanies::class)
        ->mountAction(TestAction::make('preview')->table($oldest)->arguments(['position' => 2]))
        ->getMountedActionModalHtml());

    expect($header)
        ->toContain(__('filament/record-page.preview.position', ['position' => 3, 'total' => 3, 'label' => 'Companies']))
        ->toContain($middle->getKey());
});

it('places the company where the list now shows it when a stepped position has gone stale', function (): void {
    [$oldest, $middle, $newest] = collect([3, 2, 1])
        ->map(fn (int $daysAgo): Company => Company::factory()->recycle([$this->user, $this->workspace])->create(['created_at' => now()->subDays($daysAgo)]))
        ->all();

    $header = previewHeader(livewire(ListCompanies::class)
        ->mountAction(TestAction::make('preview')->table($oldest)->arguments(['position' => 0]))
        ->getMountedActionModalHtml());

    expect($header)
        ->toContain(__('filament/record-page.preview.position', ['position' => 3, 'total' => 3, 'label' => 'Companies']))
        ->toContain($middle->getKey())
        ->not->toContain($newest->getKey());
});

it('offers the preview in the row menu for keyboard and touch users', function (string $model, string $list): void {
    $record = $model::factory()->recycle([$this->user, $this->workspace])->create();

    livewire($list)
        ->assertActionVisible(TestAction::make('preview')->table($record));
})->with('record lists');

it('creates a note from the overview of a company open in the preview', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey(), 'isEmbedded' => true])
        ->callAction(TestAction::make('createNote')->schemaComponent('quickActions', schema: 'infolist'), data: ['title' => 'Call recap'])
        ->assertHasNoActionErrors();

    expect($company->notes()->sole()->title)->toBe('Call recap');
});

it('opens the preview on the overview even when the list url names a tab', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    Livewire::withQueryParams(['relation' => '2'])
        ->test(ViewCompany::class, ['record' => $company->getKey(), 'isEmbedded' => true])
        ->assertSet('activeRelationManager', null);
});
