<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Concerns\HasRecordPageLayout;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Livewire\App\Records\DuplicateRecordsPrompt;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Queries\Crm\DuplicateRecordQuery;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\WorkspaceCustomField;

mutates(DuplicateRecordQuery::class, DuplicateRecordsPrompt::class, HasRecordPageLayout::class);

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

function duplicatePrompt(Company|People $record): Testable
{
    return livewire(DuplicateRecordsPrompt::class, ['record' => $record->fresh()]);
}

function duplicateCount(int $count): string
{
    return trans_choice('filament/record-page.merge.duplicate.count', $count);
}

it('names a company that shares a domain and opens the merge with it picked', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Logistics'], ['domains' => ['WWW.Harborline.com']]);

    duplicatePrompt($company)
        ->assertSeeText(duplicateCount(1))
        ->assertSeeText('Harborline Logistics')
        ->assertSeeText(__('filament/record-page.merge.duplicate.shares', ['value' => 'harborline.com']))
        ->assertSeeHtml('aria-label="'.__('filament/record-page.merge.duplicate.merge_with', ['name' => 'Harborline Logistics']).'"');

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('mergeDuplicate', $duplicate->getKey())
        ->assertSchemaStateSet(['picked_id' => $duplicate->getKey(), 'keep_picked' => false])
        ->callMountedAction()
        ->assertHasNoErrors();

    expect($duplicate->fresh()->trashed())->toBeTrue();

    duplicatePrompt($company)->assertViewHas('hasDuplicates', false);
});

it('names a person that shares an email in another case', function (): void {
    $person = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana Reyes'], ['emails' => ['dana@harborline.com']]);
    $duplicate = mergePersonRecord($this->workspace, $this->user, ['name' => 'Dana R.'], ['emails' => ['other@harborline.com', 'Dana@Harborline.com']]);

    duplicatePrompt($person)
        ->assertSeeText('Dana R.')
        ->assertSeeText(__('filament/record-page.merge.duplicate.shares', ['value' => 'dana@harborline.com']));

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->call('mergeDuplicate', $duplicate->getKey())
        ->assertSchemaStateSet(['picked_id' => $duplicate->getKey()]);
});

it('shows the check while it loads on the record page', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSchemaComponentVisible('duplicatePrompt', 'infolist')
        ->assertSeeText(__('filament/record-page.merge.duplicate.checking'));
});

it('shows no prompt without a live duplicate in the same workspace', function (string $case): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);

    match ($case) {
        'unshared' => mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.org']]),
        'trashed' => mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.com']])->delete(),
        'other workspace' => (function (): void {
            $other = User::factory()->withWorkspace()->create();
            TenantContextService::setTenantId($other->currentWorkspace->getKey());
            mergeCompanyRecord($other->currentWorkspace, $other, customFields: ['domains' => ['harborline.com']]);
            TenantContextService::setTenantId($this->workspace->getKey());
        })(),
    };

    duplicatePrompt($company)
        ->assertViewHas('hasDuplicates', false)
        ->assertDontSeeHtml('data-duplicate-prompt');
})->with(['unshared', 'trashed', 'other workspace']);

it('hides the prompt from a member who cannot merge', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.com']]);
    mergeCompanyRecord($this->workspace, $this->user, customFields: ['domains' => ['harborline.com']]);
    $viewer = User::factory()->create();
    $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $viewer->switchWorkspace($this->workspace);
    $this->actingAs($viewer);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertSchemaComponentHidden('duplicatePrompt', 'infolist')
        ->assertDontSeeText(__('filament/record-page.merge.duplicate.checking'));
});

it('keeps the prompt for the duplicate that is left after a merge', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    $duplicates = collect(['Harborline One', 'Harborline Two'])
        ->map(fn (string $name): Company => mergeCompanyRecord($this->workspace, $this->user, ['name' => $name], ['domains' => ['harborline.com']]));

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('mergeDuplicate', $duplicates->first()->getKey())
        ->callMountedAction();

    expect($duplicates->first()->fresh()->trashed())->toBeTrue();

    duplicatePrompt($company)
        ->assertSeeText(duplicateCount(1))
        ->assertSeeText('Harborline Two');
});

it('lists every record that matches any of its domains', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com', 'harborline.io']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline One'], ['domains' => ['harborline.com']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Two'], ['domains' => ['harborline.io']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Three'], ['domains' => ['harborline.com', 'harborline.io']]);

    duplicatePrompt($company)
        ->assertSeeText(duplicateCount(3))
        ->assertSeeTextInOrder(['Harborline One', 'Harborline Three', 'Harborline Two']);
});

it('opens the merge with the record of the row picked', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline One'], ['domains' => ['harborline.com']]);
    $second = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Two'], ['domains' => ['harborline.com']]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('mergeDuplicate', $second->getKey())
        ->assertSchemaStateSet(['picked_id' => $second->getKey()]);
});

it('picks the first duplicate when the row names a record that is not one', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    $duplicate = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline One'], ['domains' => ['harborline.com']]);
    $unrelated = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Northwind'], ['domains' => ['northwind.com']]);

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->call('mergeDuplicate', $unrelated->getKey())
        ->assertSchemaStateSet(['picked_id' => $duplicate->getKey()]);
});

it('shows no prompt when the domains attribute is switched off', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline Logistics'], ['domains' => ['harborline.com']]);
    WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'domains')->update(['active' => false]);

    duplicatePrompt($company)->assertViewHas('hasDuplicates', false);
});

it('counts more than ten duplicates as ten plus', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com', 'harborline.io']]);
    foreach (range(1, 6) as $number) {
        mergeCompanyRecord($this->workspace, $this->user, ['name' => "Harborline {$number}"], ['domains' => ['harborline.com', 'harborline.io']]);
    }
    foreach (range(7, 12) as $number) {
        mergeCompanyRecord($this->workspace, $this->user, ['name' => "Harborline {$number}"], ['domains' => ['harborline.io']]);
    }
    foreach (range(13, 15) as $number) {
        mergeCompanyRecord($this->workspace, $this->user, ['name' => "Harborline {$number}"], ['domains' => ['harborline.com']]);
    }

    $prompt = duplicatePrompt($company)->assertSeeText(__('filament/record-page.merge.duplicate.count_over', ['count' => 10]));

    expect(substr_count($prompt->html(), 'wire:key="duplicate-'))->toBe(10);
});

it('counts exactly ten duplicates without a plus', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    foreach (range(1, 10) as $number) {
        mergeCompanyRecord($this->workspace, $this->user, ['name' => "Harborline {$number}"], ['domains' => ['harborline.com']]);
    }

    duplicatePrompt($company)->assertSeeText(duplicateCount(10));
});

it('keeps a quote in the duplicate name inside the merge button label', function (): void {
    $company = mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harborline'], ['domains' => ['harborline.com']]);
    mergeCompanyRecord($this->workspace, $this->user, ['name' => 'Harbor" data-injected="1'], ['domains' => ['harborline.com']]);

    duplicatePrompt($company)
        ->assertDontSeeHtml('data-injected="1"')
        ->assertSeeHtml('aria-label="'.e(__('filament/record-page.merge.duplicate.merge_with', ['name' => 'Harbor" data-injected="1'])).'"');
});
