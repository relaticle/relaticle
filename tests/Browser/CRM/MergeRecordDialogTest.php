<?php

declare(strict_types=1);

use App\Filament\Actions\MergeRecordAction;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\CustomFields\Services\TenantContextService;

mutates(MergeRecordAction::class);

beforeEach(function (): void {
    $this->withVite();
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
    TenantContextService::setTenantId($this->workspace->getKey());
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

it('merges a duplicate picked from the dialog into the current company', function (): void {
    $kept = dialogCompany($this->workspace, $this->user, 'Brightline Health', ['brightlinehealth.com']);
    $duplicate = dialogCompany($this->workspace, $this->user, 'Brightline Health Inc.', ['brightline.health']);
    dialogCompany($this->workspace, $this->user, 'Northwind Traders');

    $page = openMergeDialog($this->user, $this->workspace, $kept)
        ->assertSeeIn('[data-merge-right]', 'Brightline Health')
        ->assertVisible('[data-merge-picker]')
        ->assertScript(MERGE_SUBMIT_DISABLED, true)
        ->assertScript('document.querySelector("[data-merge-swap]").disabled', true);

    $page->click('[data-merge-picker]')
        ->assertAttribute('[data-merge-popover] input', 'placeholder', 'Search by name or domain')
        ->assertAttribute('[data-merge-popover] input', 'aria-activedescendant', 'merge-option-0')
        ->type('[data-merge-popover] input', 'zzzz')
        ->wait(1.2)
        ->assertAttributeMissing('[data-merge-popover] input', 'aria-activedescendant')
        ->assertAttributeMissing('[data-merge-popover] input', 'aria-controls')
        ->keys('[data-merge-popover] input', 'Escape');

    pickInMergeDialog($page, 'brightline.health', 'Brightline Health Inc.')
        ->assertSeeIn('[data-merge-left]', 'Brightline Health Inc.')
        ->assertSeeIn('[data-merge-result]', 'brightlinehealth.com')
        ->assertSeeIn('[data-merge-result]', 'brightline.health')
        ->assertScript(MERGE_SUBMIT_DISABLED, false)
        ->click('.fi-modal-footer-actions button[type=submit]')
        ->wait(1.5)
        ->assertPathIs("/app/{$this->workspace->slug}/companies/{$kept->getKey()}")
        ->assertSee('Records merged')
        ->assertNoJavaScriptErrors();

    expect(Company::query()->find($duplicate->getKey()))->toBeNull();
});

it('keeps the other company when the member swaps the two', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Northwind', ['northwind.com']);
    $other = dialogCompany($this->workspace, $this->user, 'Northwind Traders', ['northwindtraders.com']);

    $page = openMergeDialog($this->user, $this->workspace, $current);

    pickInMergeDialog($page, 'Traders', 'Northwind Traders')
        ->click('[data-merge-swap]')
        ->wait(1.2)
        ->assertSeeIn('[data-merge-right]', 'Northwind Traders')
        ->assertSeeIn('[data-merge-left] [data-merge-name]', 'Northwind')
        ->click('.fi-modal-footer-actions button[type=submit]')
        ->wait(1.5)
        ->assertPathIs("/app/{$this->workspace->slug}/companies/{$other->getKey()}")
        ->assertSee('Records merged')
        ->assertNoJavaScriptErrors();

    expect(Company::query()->find($current->getKey()))->toBeNull();
});

it('disables merging and names the list over its limit', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Acme', ['a1.com', 'a2.com', 'a3.com']);
    dialogCompany($this->workspace, $this->user, 'Acme Holdings', ['b1.com', 'b2.com', 'b3.com']);

    $page = openMergeDialog($this->user, $this->workspace, $current);

    pickInMergeDialog($page, 'Holdings', 'Acme Holdings')
        ->assertSeeIn('[data-merge-over-limit]', 'Domains can hold 5 values')
        ->assertScript(MERGE_SUBMIT_DISABLED, true)
        ->assertNoJavaScriptErrors();
});

it('shows domains as links that open a new tab and a logo in the list and the card', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Globex', ['globex.com']);
    $other = dialogCompany($this->workspace, $this->user, 'Globex Europe', ['globex.eu']);
    $other->addMediaFromString(onePixelPng())->usingFileName('logo.png')->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);

    $links = <<<'JS'
        [...document.querySelectorAll('[data-merge-dialog] [data-merge-details] a')].map((link) => [link.getAttribute('href'), link.target, link.rel])
        JS;

    $page = openMergeDialog($this->user, $this->workspace, $current)
        ->click('[data-merge-picker]')
        ->wait(1.2)
        ->assertPresent('[data-merge-popover] li:has-text("Globex Europe") img');

    expect($page->script($links))->toBe([['https://globex.com', '_blank', 'noopener noreferrer'], ['https://globex.com', '_blank', 'noopener noreferrer']]);

    $visibleInResultTile = <<<'JS'
        [...document.querySelector('[data-merge-result] span[aria-hidden="true"]').children].filter((child) => child.checkVisibility()).map((child) => child.tagName.toLowerCase())
        JS;

    expect($page->script($visibleInResultTile))->toBe(['svg']);

    $page->click('[data-merge-popover] li:has-text("Globex Europe")')
        ->wait(1.2)
        ->assertPresent('[data-merge-left] img');

    expect($page->script($links))->toContain(['https://globex.eu', '_blank', 'noopener noreferrer'])
        ->and($page->script($visibleInResultTile))->toBe(['img']);
    $page->assertNoJavaScriptErrors();
});

it('merges a duplicate person picked from the dialog and shows emails and contact opportunities', function (): void {
    $kept = dialogPerson($this->workspace, $this->user, 'Dana Whitfield', ['dana@brightline.com']);
    $duplicate = dialogPerson($this->workspace, $this->user, 'Dana W.', ['dana@gmail.com']);
    dialogPerson($this->workspace, $this->user, 'Marcus Chen', ['marcus@northwind.com']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $duplicate->getKey()]);

    $page = openMergeDialog($this->user, $this->workspace, $kept)
        ->assertSeeIn('[data-merge-right]', 'dana@brightline.com')
        ->click('[data-merge-picker]')
        ->assertAttribute('[data-merge-popover] input', 'placeholder', 'Search by name or email')
        ->keys('[data-merge-popover] input', 'Escape');

    pickInMergeDialog($page, 'dana@gmail', 'Dana W.')
        ->assertSeeIn('[data-merge-left]', 'dana@gmail.com')
        ->assertSeeIn('[data-merge-result]', 'dana@brightline.com')
        ->assertSeeIn('[data-merge-result]', 'dana@gmail.com')
        ->assertScript('document.querySelector("[data-merge-result] [data-merge-counts] li .sr-only").textContent', '1 opportunity as point of contact')
        ->assertScript(MERGE_SUBMIT_DISABLED, false)
        ->click('.fi-modal-footer-actions button[type=submit]')
        ->wait(1.5)
        ->assertPathIs("/app/{$this->workspace->slug}/people/{$kept->getKey()}")
        ->assertSee('Records merged')
        ->assertNoJavaScriptErrors();

    expect(People::query()->find($duplicate->getKey()))->toBeNull();
});
