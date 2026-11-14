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
        ->click("[data-merge-popover] li button:has-text(\"{$name}\")")
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

    pickInMergeDialog($page, 'Inc', 'Brightline Health Inc.')
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

it('closes the search with Escape and picks a record with Enter', function (): void {
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
        ->keys('[data-merge-popover] li button:has-text("Globex Europe")', 'Enter')
        ->wait(1.2)
        ->assertSeeIn('[data-merge-left]', 'Globex Europe')
        ->assertScript(MERGE_SUBMIT_DISABLED, false);
});

it('stacks the cards on a phone', function (): void {
    $current = dialogCompany($this->workspace, $this->user, 'Initech', ['initech.com']);
    dialogCompany($this->workspace, $this->user, 'Initech Ltd', ['initech.co.uk']);

    $page = openMergeDialog($this->user, $this->workspace, $current, 390, 844);

    pickInMergeDialog($page, 'Ltd', 'Initech Ltd');

    $layout = <<<'JS'
        (() => {
            const top = (selector) => document.querySelector(selector).getBoundingClientRect();
            const left = top('[data-merge-left]');
            const right = top('[data-merge-right]');
            const result = top('[data-merge-result]');

            return {
                stacked: right.top >= left.bottom && result.top >= right.bottom,
                overflows: document.documentElement.scrollWidth > document.documentElement.clientWidth
                    || document.querySelector('.fi-modal-window').scrollWidth > document.querySelector('.fi-modal-window').clientWidth,
            };
        })();
    JS;

    expect($page->script($layout))->toMatchArray(['stacked' => true, 'overflows' => false]);
});

it('merges a duplicate person picked from the dialog and shows emails and contact opportunities', function (): void {
    $kept = dialogPerson($this->workspace, $this->user, 'Dana Whitfield', ['dana@brightline.com']);
    $duplicate = dialogPerson($this->workspace, $this->user, 'Dana W.', ['dana@gmail.com']);
    dialogPerson($this->workspace, $this->user, 'Marcus Chen', ['marcus@northwind.com']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => $duplicate->getKey()]);

    $page = openMergeDialog($this->user, $this->workspace, $kept)
        ->assertSeeIn('[data-merge-right]', 'dana@brightline.com');

    pickInMergeDialog($page, 'Dana W', 'Dana W.')
        ->assertSeeIn('[data-merge-left]', 'dana@gmail.com')
        ->assertSeeIn('[data-merge-result]', 'dana@brightline.com')
        ->assertSeeIn('[data-merge-result]', 'dana@gmail.com')
        ->assertScript('document.querySelector("[data-merge-result] [data-merge-counts] li").title', '1 opportunity as point of contact')
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
            const last = list.querySelector('li:last-child button').getBoundingClientRect();
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
