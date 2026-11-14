<?php

declare(strict_types=1);

use App\Filament\Actions\MergeRecordAction;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Pest\Browser\Api\AwaitableWebpage;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\WorkspaceCustomField;

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

function dialogCompany(Workspace $workspace, User $owner, string $name, array $domains = []): Company
{
    $company = Company::factory()->recycle([$owner, $workspace])->create(['name' => $name]);

    if ($domains !== []) {
        $company->saveCustomFieldValue(WorkspaceCustomField::byCode($workspace->getKey(), 'company', 'domains'), $domains);
    }

    return $company;
}

function dialogPerson(Workspace $workspace, User $owner, string $name, array $emails = []): People
{
    $person = People::factory()->recycle([$owner, $workspace])->create(['name' => $name]);

    if ($emails !== []) {
        $person->saveCustomFieldValue(WorkspaceCustomField::byCode($workspace->getKey(), 'people', 'emails'), $emails);
    }

    return $person;
}

function openMergeDialog(User $user, Workspace $workspace, Company|People $record, int $width = 1440, int $height = 900): AwaitableWebpage
{
    $segment = $record instanceof People ? 'people' : 'companies';

    return visitAs($user, "/app/{$workspace->slug}/{$segment}/{$record->getKey()}", $width, $height)
        ->click('.fi-record-rail-actions .fi-dropdown-trigger button')
        ->click('.fi-dropdown-list-item:has-text("Merge record")')
        ->assertVisible('[data-merge-dialog]');
}

function pickInMergeDialog(AwaitableWebpage $page, string $search, string $name): AwaitableWebpage
{
    return $page->click('[data-merge-picker]')
        ->type('[data-merge-popover] input', $search)
        ->wait(1.2)
        ->click("[data-merge-popover] li:has-text(\"{$name}\")")
        ->wait(1.2);
}

const MERGE_SUBMIT_DISABLED = 'document.querySelector(".fi-modal-footer-actions button[type=submit]").getAttribute("aria-disabled") === "true"';

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

it('closes the search with Escape and picks a record with a click', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Globex');
    dialogCompany($this->workspace, $this->user, 'Globex Europe');

    $page = openMergeDialog($this->user, $this->workspace, $current)
        ->click('[data-merge-picker]')
        ->assertVisible('[data-merge-popover]')
        ->keys('[data-merge-popover] input', 'Escape')
        ->assertScript('getComputedStyle(document.querySelector("[data-merge-popover]")).display', 'none')
        ->assertVisible('[data-merge-dialog]');

    $page->click('[data-merge-picker]')
        ->assertVisible('[data-merge-popover]')
        ->click('[data-merge-popover] li:has-text("Globex Europe")')
        ->wait(1.2)
        ->assertSeeIn('[data-merge-left]', 'Globex Europe')
        ->assertScript(MERGE_SUBMIT_DISABLED, false);
});

it('picks the first result when Enter is pressed in the search', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Globex');
    dialogCompany($this->workspace, $this->user, 'Globex Europe');
    dialogCompany($this->workspace, $this->user, 'Globex Asia');

    openMergeDialog($this->user, $this->workspace, $current)
        ->click('[data-merge-picker]')
        ->type('[data-merge-popover] input', 'Globex')
        ->wait(1.2)
        ->keys('[data-merge-popover] input', 'Enter')
        ->wait(1.2)
        ->assertSeeIn('[data-merge-left]', 'Globex Asia')
        ->assertScript(MERGE_SUBMIT_DISABLED, false)
        ->assertNoJavaScriptErrors();
});

it('moves the highlighted result with the arrow keys before Enter picks it', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Globex');
    dialogCompany($this->workspace, $this->user, 'Globex Europe');
    dialogCompany($this->workspace, $this->user, 'Globex Asia');

    openMergeDialog($this->user, $this->workspace, $current)
        ->click('[data-merge-picker]')
        ->type('[data-merge-popover] input', 'Globex')
        ->wait(1.2)
        ->keys('[data-merge-popover] input', 'ArrowDown')
        ->assertAttribute('[data-merge-popover] li:nth-child(2)', 'aria-selected', 'true')
        ->keys('[data-merge-popover] input', 'Enter')
        ->wait(1.2)
        ->assertSeeIn('[data-merge-left]', 'Globex Europe')
        ->assertNoJavaScriptErrors();
});

it('moves focus to the clear button once a record is picked and back to the plus when cleared', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Globex');
    dialogCompany($this->workspace, $this->user, 'Globex Europe');

    $page = openMergeDialog($this->user, $this->workspace, $current);

    pickInMergeDialog($page, 'Europe', 'Globex Europe')
        ->assertScript('document.activeElement.matches("[data-merge-clear]")', true)
        ->click('[data-merge-clear]')
        ->wait(1.2)
        ->assertScript('document.activeElement.matches("[data-merge-picker]")', true)
        ->assertNoJavaScriptErrors();
});

it('closes the search list when focus tabs out of it', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Globex');
    dialogCompany($this->workspace, $this->user, 'Globex Europe');

    openMergeDialog($this->user, $this->workspace, $current)
        ->click('[data-merge-picker]')
        ->assertVisible('[data-merge-popover]')
        ->keys('[data-merge-popover] input', 'Tab')
        ->assertScript('getComputedStyle(document.querySelector("[data-merge-popover]")).display', 'none')
        ->assertVisible('[data-merge-dialog]')
        ->assertNoJavaScriptErrors();
});

it('previews the current company alone and looks disabled until a record is picked', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Globex', ['globex.com']);
    dialogCompany($this->workspace, $this->user, 'Globex Europe');

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
    $kept = dialogCompany($this->workspace, $this->user, 'Globex');
    $duplicate = dialogCompany($this->workspace, $this->user, 'Globex Europe');

    $page = openMergeDialog($this->user, $this->workspace, $kept);

    pickInMergeDialog($page, 'Europe', 'Globex Europe')
        ->script('document.activeElement.blur()');

    $page->keys('[data-merge-dialog]', 'Enter')
        ->wait(1.5)
        ->assertPathIs("/app/{$this->workspace->slug}/companies/{$kept->getKey()}")
        ->assertNoJavaScriptErrors();

    expect(Company::query()->find($duplicate->getKey()))->toBeNull();
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

    $page->click('[data-merge-popover] li:has-text("Globex Europe")')
        ->wait(1.2)
        ->assertPresent('[data-merge-left] img');

    expect($page->script($links))->toContain(['https://globex.eu', '_blank', 'noopener noreferrer']);
    $page->assertNoJavaScriptErrors();
});

const MERGE_LAYOUT = <<<'JS'
    (() => {
        const rect = (selector) => document.querySelector(selector).getBoundingClientRect();
        const left = rect('[data-merge-left]');
        const right = rect('[data-merge-right]');
        const result = rect('[data-merge-result]');
        const swap = rect('[data-merge-swap]');
        const swapX = swap.left + swap.width / 2;
        const swapY = swap.top + swap.height / 2;
        const tolerance = 1.5;
        const intersects = (a, b) => a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
        const border = (selector) => parseFloat(getComputedStyle(document.querySelector(selector)).borderTopWidth);

        return {
            sameRow: Math.abs(left.top - right.top) < tolerance && Math.abs(left.top - result.top) < tolerance,
            sameHeight: Math.abs(left.height - right.height) < tolerance && Math.abs(left.height - result.height) < tolerance,
            sameWidth: Math.abs(left.width - right.width) < tolerance && Math.abs(left.width - result.width) < tolerance,
            resultAfterSources: result.left >= right.right,
            swapClear: ! intersects(swap, left) && ! intersects(swap, right),
            sourcesBordered: border('[data-merge-left] > div') > 0 && border('[data-merge-right] > div') > 0,
            swapBetweenSlots: swapX > left.right && swapX < right.left,
            swapStacked: swapY > left.bottom && swapY < right.top,
            resultBelow: result.top >= right.bottom,
            overflows: document.documentElement.scrollWidth > document.documentElement.clientWidth
                || document.querySelector('.fi-modal-window').scrollWidth > document.querySelector('.fi-modal-window').clientWidth,
        };
    })();
JS;

it('lays the sources and the result in one row on a laptop and a desktop', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Harborline', ['harborline.com']);
    dialogCompany($this->workspace, $this->user, 'Harborline Logistics', ['harborline-logistics.com']);

    foreach ([[1440, 900], [1280, 800]] as [$width, $height]) {
        $page = openMergeDialog($this->user, $this->workspace, $current, $width, $height);

        expect($page->script(MERGE_LAYOUT))->toMatchArray([
            'sameRow' => true,
            'sameHeight' => true,
            'sameWidth' => true,
            'resultAfterSources' => true,
            'swapBetweenSlots' => true,
            'swapClear' => true,
            'sourcesBordered' => true,
            'overflows' => false,
        ]);

        pickInMergeDialog($page, 'Logistics', 'Harborline Logistics');

        expect($page->script(MERGE_LAYOUT))->toMatchArray([
            'sameRow' => true,
            'sameHeight' => true,
            'sameWidth' => true,
            'resultAfterSources' => true,
            'swapBetweenSlots' => true,
            'swapClear' => true,
            'sourcesBordered' => true,
            'overflows' => false,
        ]);
    }
});

it('stacks the cards on a phone with the swap button between the two sources', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Initech', ['initech.com']);
    dialogCompany($this->workspace, $this->user, 'Initech Ltd', ['initech.co.uk']);

    $page = openMergeDialog($this->user, $this->workspace, $current, 390, 844);

    pickInMergeDialog($page, 'Ltd', 'Initech Ltd');

    expect($page->script(MERGE_LAYOUT))->toMatchArray([
        'sameWidth' => true,
        'swapStacked' => true,
        'swapClear' => true,
        'sourcesBordered' => true,
        'resultBelow' => true,
        'overflows' => false,
    ]);
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

it('caps the open search list inside the window and scrolls it to the last row', function (int $width, int $height): void {
    $current = dialogCompany($this->workspace, $this->user, 'Harborline');

    foreach (range(1, 30) as $index) {
        dialogCompany($this->workspace, $this->user, sprintf('Branch %02d', $index));
    }

    $page = openMergeDialog($this->user, $this->workspace, $current, $width, $height)
        ->click('[data-merge-picker]')
        ->assertVisible('[data-merge-popover]')
        ->wait(1.2);

    $measure = <<<'JS'
        (() => {
            const popover = document.querySelector('[data-merge-popover]');
            const list = popover.querySelector('ul');
            const search = popover.querySelector('input').getBoundingClientRect();
            const box = popover.getBoundingClientRect();
            list.scrollTop = list.scrollHeight;
            const last = list.querySelector('li:last-child').getBoundingClientRect();
            const bounds = list.getBoundingClientRect();

            return {
                height: box.height <= 448,
                inWindow: box.top >= 0 && box.bottom <= window.innerHeight - 16,
                listScrolls: list.scrollHeight > list.clientHeight,
                searchFixed: search.top >= box.top && search.bottom <= bounds.top + 1,
                lastReachable: last.bottom <= bounds.bottom + 1 && last.top >= bounds.top - 1,
                pageOverflows: document.documentElement.scrollWidth > document.documentElement.clientWidth,
            };
        })()
        JS;

    expect($page->script($measure))->toBe([
        'height' => true,
        'inWindow' => true,
        'listScrolls' => true,
        'searchFixed' => true,
        'lastReachable' => true,
        'pageOverflows' => false,
    ]);
    $page->assertNoJavaScriptErrors();
})->with([
    'desktop' => [1440, 700],
    'short window' => [1440, 560],
    'phone' => [390, 844],
]);
