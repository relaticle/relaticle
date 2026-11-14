<?php

declare(strict_types=1);

use App\Filament\Actions\MergeRecordAction;
use App\Models\Company;
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

it('closes the search with Escape and picks a record with a click', function (): void {
    $current = mergeDialogCompany($this->workspace, $this->user, 'Globex');
    mergeDialogCompany($this->workspace, $this->user, 'Globex Europe');

    $page = openMergeDialog($this->user, $this->workspace, $current)
        ->click('[data-merge-picker]')
        ->assertVisible('.fi-merge-pop')
        ->keys('.fi-merge-pop input', 'Escape')
        ->assertScript('getComputedStyle(document.querySelector(".fi-merge-pop")).visibility', 'hidden')
        ->assertVisible('[data-merge-dialog]');

    $page->click('[data-merge-picker]')
        ->assertVisible('.fi-merge-pop')
        ->click('.fi-merge-pop li:has-text("Globex Europe")')
        ->wait(1.2)
        ->assertSeeIn('[data-merge-left]', 'Globex Europe')
        ->assertScript(MERGE_SUBMIT_DISABLED, false);
});

it('picks the first result when Enter is pressed in the search', function (): void {
    $current = mergeDialogCompany($this->workspace, $this->user, 'Globex');
    mergeDialogCompany($this->workspace, $this->user, 'Globex Europe');
    mergeDialogCompany($this->workspace, $this->user, 'Globex Asia');

    openMergeDialog($this->user, $this->workspace, $current)
        ->click('[data-merge-picker]')
        ->type('.fi-merge-pop input', 'Globex')
        ->wait(1.2)
        ->keys('.fi-merge-pop input', 'Enter')
        ->wait(1.2)
        ->assertSeeIn('[data-merge-left]', 'Globex Asia')
        ->assertScript(MERGE_SUBMIT_DISABLED, false)
        ->assertNoJavaScriptErrors();
});

it('moves the highlighted result with the arrow keys before Enter picks it', function (): void {
    $current = mergeDialogCompany($this->workspace, $this->user, 'Globex');
    mergeDialogCompany($this->workspace, $this->user, 'Globex Europe');
    mergeDialogCompany($this->workspace, $this->user, 'Globex Asia');

    openMergeDialog($this->user, $this->workspace, $current)
        ->click('[data-merge-picker]')
        ->type('.fi-merge-pop input', 'Globex')
        ->wait(1.2)
        ->keys('.fi-merge-pop input', 'ArrowDown')
        ->assertAttribute('.fi-merge-pop li:nth-child(2)', 'aria-selected', 'true')
        ->keys('.fi-merge-pop input', 'Enter')
        ->wait(1.2)
        ->assertSeeIn('[data-merge-left]', 'Globex Europe')
        ->assertNoJavaScriptErrors();
});

it('moves focus to the clear button once a record is picked and back to the plus when cleared', function (): void {
    $current = mergeDialogCompany($this->workspace, $this->user, 'Globex');
    mergeDialogCompany($this->workspace, $this->user, 'Globex Europe');

    $page = openMergeDialog($this->user, $this->workspace, $current);

    pickInMergeDialog($page, 'Europe', 'Globex Europe')
        ->assertScript('document.activeElement.matches("[data-merge-clear]")', true)
        ->click('[data-merge-clear]')
        ->wait(1.2)
        ->assertScript('document.activeElement.matches("[data-merge-picker]")', true)
        ->assertNoJavaScriptErrors();
});

it('closes the search list when focus tabs out of it', function (): void {
    $current = mergeDialogCompany($this->workspace, $this->user, 'Globex');
    mergeDialogCompany($this->workspace, $this->user, 'Globex Europe');

    openMergeDialog($this->user, $this->workspace, $current)
        ->click('[data-merge-picker]')
        ->assertVisible('.fi-merge-pop')
        ->keys('.fi-merge-pop input', 'Tab')
        ->assertScript('getComputedStyle(document.querySelector(".fi-merge-pop")).visibility', 'hidden')
        ->assertVisible('[data-merge-dialog]')
        ->assertNoJavaScriptErrors();
});

it('previews the current company alone and looks disabled until a record is picked', function (): void {
    $current = mergeDialogCompany($this->workspace, $this->user, 'Globex', ['globex.com']);
    mergeDialogCompany($this->workspace, $this->user, 'Globex Europe');

    $look = <<<'JS'
        (() => {
            const style = getComputedStyle(document.querySelector('.fi-modal-footer-actions button[type=submit]'));

            return { opacity: style.opacity, cursor: style.cursor };
        })()
        JS;

    $page = openMergeDialog($this->user, $this->workspace, $current)
        ->assertSeeIn('[data-merge-result] [data-merge-name]', 'Globex')
        ->assertSeeIn('[data-merge-result]', 'globex.com')
        ->assertScript(MERGE_SUBMIT_DISABLED, true);

    expect($page->script($look))->toBe(['opacity' => '0.5', 'cursor' => 'not-allowed']);

    $page->script('document.querySelector(".fi-modal-footer-actions button[type=submit]").click()');
    $page->wait(0.8)->assertVisible('[data-merge-dialog]')->assertMissing('[data-merge-error]');
    $page->script('document.activeElement.blur()');
    $page->keys('[data-merge-dialog]', 'Enter')->wait(0.8)->assertVisible('[data-merge-dialog]')->assertMissing('[data-merge-error]');

    pickInMergeDialog($page, 'Europe', 'Globex Europe');

    expect($page->script($look))->toBe(['opacity' => '1', 'cursor' => 'pointer']);
});

it('merges when Enter is pressed outside the search once a record is picked', function (): void {
    $kept = mergeDialogCompany($this->workspace, $this->user, 'Globex');
    $duplicate = mergeDialogCompany($this->workspace, $this->user, 'Globex Europe');

    $page = openMergeDialog($this->user, $this->workspace, $kept);

    pickInMergeDialog($page, 'Europe', 'Globex Europe')
        ->script('document.activeElement.blur()');

    $page->keys('[data-merge-dialog]', 'Enter')
        ->wait(1.5)
        ->assertPathIs("/app/{$this->workspace->slug}/companies/{$kept->getKey()}")
        ->assertNoJavaScriptErrors();

    expect(Company::query()->find($duplicate->getKey()))->toBeNull();
});
