<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\CustomFieldType;
use App\Filament\Components\Tables\RecordChipColumn;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Providers\Filament\AppPanelProvider;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;

mutates(AppPanelProvider::class, RecordChipColumn::class);

const CELL_LABELS_SHOWN = '[...document.querySelectorAll(".fi-ta-row .fi-ta-cell-label")].some((label) => label.offsetParent !== null)';

const ROWS_STACKED = '[...document.querySelectorAll(".fi-ta-row")].every((row) => getComputedStyle(row).display !== "table-row")';

const SEARCH_FILLS_TOOLBAR = '(() => { const toolbar = document.querySelector(".fi-ta-header-toolbar"); const style = getComputedStyle(toolbar); const start = toolbar.getBoundingClientRect().left + parseFloat(style.paddingLeft); const width = toolbar.clientWidth - parseFloat(style.paddingLeft) - parseFloat(style.paddingRight); const group = toolbar.querySelector(":scope > :has(> .fi-ta-search-field)"); const used = [...toolbar.children].filter((child) => child.getBoundingClientRect().width > 0).reduce((sum, child) => sum + child.getBoundingClientRect().width + parseFloat(getComputedStyle(child).marginInlineStart) + parseFloat(getComputedStyle(child).marginInlineEnd), 0); return Math.round(group.getBoundingClientRect().left) === Math.round(start) && Math.round(used) === Math.round(width); })()';

const TABLE_SCROLLS_SIDEWAYS = '(() => { const content = document.querySelector(".fi-ta-content-ctn"); return content.scrollWidth > content.clientWidth; })()';

function seedListRows(User $user): void
{
    $workspace = $user->ownedWorkspaces()->first();

    $company = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Northwind Traders International Holdings Group']);
    People::factory()->recycle([$user, $workspace])->create(['company_id' => $company->getKey()]);
    $opportunity = Opportunity::factory()->recycle([$user, $workspace])->create();
    $task = Task::factory()->recycle([$user, $workspace])->create();
    $task->companies()->attach($company);

    TenantContextService::setTenantId($workspace->getKey());
    $amount = CustomField::query()->forEntity(Opportunity::class)->where('code', OpportunityField::AMOUNT->value)->firstOrFail();
    $opportunity->saveCustomFieldValue($amount, 5000);
    TenantContextService::setTenantId(null);

    Import::factory()->create([
        'workspace_id' => (string) $workspace->getKey(),
        'user_id' => (string) $user->getKey(),
        'status' => ImportStatus::Completed,
    ]);
}

it('stacks each list row into a card so no column sits off a phone screen', function (string $path, string $filledColumn, bool $searchable): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    visitAs($user, "/app/{$workspace->slug}/{$path}", 390, 844)
        ->assertVisible('.fi-ta-row')
        ->assertScript(ROWS_STACKED, true)
        ->assertScript(CELL_LABELS_SHOWN, true)
        ->assertScript("(() => { const cell = document.querySelector('.fi-ta-row [class~=\"fi-ta-cell-{$filledColumn}\"]'); return getComputedStyle(cell).display === 'grid' && cell.querySelector('.fi-ta-cell-label').offsetParent !== null; })()", true)
        ->assertScript(TABLE_SCROLLS_SIDEWAYS, false)
        ->assertScript($searchable ? SEARCH_FILLS_TOOLBAR : 'document.querySelector(".fi-ta-search-field") === null', true)
        ->assertNoJavaScriptErrors();
})->with([
    'tasks' => ['tasks', 'relations', false],
    'companies' => ['companies', 'updated-at', false],
    'people' => ['people', 'company.name', false],
    'opportunities' => ['opportunities?view=all', 'custom-fields.amount', false],
    'import-history' => ['import-history', 'status', true],
]);

it('shows the bulk bar only while a record is selected, and the rows stay put', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    $firstRowTop = 'Math.round(document.querySelector(".fi-ta-row").getBoundingClientRect().top)';

    $page = visitAs($user, "/app/{$workspace->slug}/companies", 1440, 900)
        ->assertVisible('[data-filter-bar]')
        ->assertMissing('.fi-bulk-bar');

    $before = $page->script($firstRowTop);

    $page->click('.fi-ta-record-checkbox')
        ->assertVisible('.fi-bulk-bar')
        ->assertSeeIn('.fi-bulk-bar-count', '1')
        ->assertSeeIn('.fi-bulk-bar-selection', 'Selected')
        ->assertMissing('.fi-ta-selection-indicator')
        ->assertScript($firstRowTop, $before)
        ->click('.fi-bulk-bar-selection')
        ->assertMissing('.fi-bulk-bar')
        ->assertNoJavaScriptErrors();
});

it('moves focus to the select all checkbox when the selection is cleared from the bulk bar', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    visitAs($user, "/app/{$workspace->slug}/companies", 1440, 900)
        ->click('.fi-ta-record-checkbox')
        ->assertVisible('.fi-bulk-bar')
        ->click('.fi-bulk-bar-selection')
        ->assertMissing('.fi-bulk-bar')
        ->assertScript('document.activeElement === document.querySelector(".fi-ta-table thead .fi-ta-selection-cell input[type=checkbox]")', true)
        ->assertNoJavaScriptErrors();
});

it('deletes a selected record from the bulk bar\'s More menu, which opens above the bar', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $company = Company::factory()->recycle([$user, $workspace])->create(['name' => 'Atlas Freight']);

    $menuAboveBar = <<<'JS'
        (() => {
            const panel = [...document.querySelectorAll('.fi-dropdown-panel')].find((candidate) => candidate.style.display === 'block');

            return Math.round(panel.getBoundingClientRect().bottom) <= Math.round(document.querySelector('.fi-bulk-bar').getBoundingClientRect().top);
        })();
    JS;

    visitAs($user, "/app/{$workspace->slug}/companies", 1440, 900)
        ->click('.fi-ta-record-checkbox')
        ->click('.fi-bulk-bar .fi-dropdown-trigger')
        ->assertScript($menuAboveBar, true)
        ->click('Delete selected')
        ->click('.fi-modal-open .fi-modal-footer-actions button.fi-color-danger')
        ->assertDontSee('Atlas Freight')
        ->assertMissing('.fi-bulk-bar')
        ->assertNoJavaScriptErrors();

    expect($company->fresh()->trashed())->toBeTrue();
});

it('keeps the list table header row on desktop', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    visitAs($user, "/app/{$workspace->slug}/tasks", 1440, 900)
        ->assertVisible('.fi-ta-header-cell.fi-ta-header-cell-title')
        ->assertScript(CELL_LABELS_SHOWN, false)
        ->assertNoJavaScriptErrors();
});

it('keeps a linked company chip inside its cell link', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    visitAs($user, "/app/{$workspace->slug}/people")
        ->assertScript('document.querySelector(\'[class~="fi-ta-cell-company.name"] a.fi-ta-col .fi-record-chip\') !== null', true)
        ->assertNoJavaScriptErrors();
});

it('keeps a long record name inside its phone card, clear of its checkbox', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Company::factory()->recycle([$user, $workspace])->create(['name' => 'Long Name Industries International Holdings Limited']);

    $titleClearOfCheckbox = <<<'JS'
        (() => {
            const row = document.querySelector('.fi-ta-row');
            const range = document.createRange();
            range.selectNodeContents(row.querySelector('.fi-ta-cell-content .fi-record-chip, .fi-ta-cell-content .fi-ta-text-item'));

            return Math.ceil(range.getBoundingClientRect().right) <= Math.floor(row.querySelector('.fi-ta-record-checkbox').getBoundingClientRect().left);
        })();
    JS;

    visitAs($user, "/app/{$workspace->slug}/companies", 320, 568)
        ->assertVisible('.fi-ta-row')
        ->assertScript($titleClearOfCheckbox, true)
        ->assertNoJavaScriptErrors();
});

it('hides a column set visibleFrom sm in a phone card and shows it from sm up', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Company::factory()->recycle([$user, $workspace])->create();

    $createdAtDisplayed = 'getComputedStyle(document.querySelector(\'.fi-ta-row [class~="fi-ta-cell-created-at"]\')).display !== "none"';

    $page = visitAs($user, "/app/{$workspace->slug}/companies", 390, 844)
        ->assertVisible('.fi-ta-row')
        ->assertScript($createdAtDisplayed, false);

    $page->resize(1440, 900)
        ->assertScript($createdAtDisplayed, true)
        ->assertNoJavaScriptErrors();
});

it('hides a phone card row whose value is empty', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    People::factory()->recycle([$user, $workspace])->create(['company_id' => null]);

    $emptyRowShown = 'getComputedStyle(document.querySelector(\'.fi-ta-row [class~="fi-ta-cell-company.name"]\')).display !== "none"';

    visitAs($user, "/app/{$workspace->slug}/people", 390, 844)
        ->assertVisible('.fi-ta-row')
        ->assertScript($emptyRowShown, false)
        ->assertNoJavaScriptErrors();
});

it('keeps every filter field option inside the phone filter sheet', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Company::factory()->recycle([$user, $workspace])->create();

    foreach (range(1, 12) as $number) {
        resolve(CreateCustomField::class)->execute($user, [
            'entity_type' => 'company',
            'name' => "Note {$number}",
            'code' => "note_{$number}",
            'type' => CustomFieldType::TEXT->value,
        ]);
    }

    $page = visitAs($user, "/app/{$workspace->slug}/companies", 390, 664)
        ->assertVisible('.fi-ta-row')
        ->click('[data-filter-add]')
        ->assertVisible('[data-filter-bar] [data-pop-item] >> nth=0')
        ->assertNoJavaScriptErrors();

    $lastOptionReachable = <<<'JS'
        (() => {
            const sheet = [...document.querySelectorAll('[data-filter-bar] [data-pop]')].find((pop) => pop.checkVisibility());
            const options = [...sheet.querySelectorAll('[data-pop-item]')];
            const last = options.at(-1);

            last.scrollIntoView({ block: 'nearest' });

            const box = last.getBoundingClientRect();
            const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

            return {
                listsEveryField: options.length >= 13,
                insideScreen: box.bottom <= innerHeight && box.top >= 0,
                reachable: last.contains(hit),
            };
        })();
    JS;

    expect($page->script($lastOptionReachable))->toBe(['listsEveryField' => true, 'insideScreen' => true, 'reachable' => true]);
});

it('keeps the bulk bar above the tab bar on a phone while rows are ticked', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Company::factory()->count(15)->recycle([$user, $workspace])->create();

    $barClearOfTabBar = <<<'JS'
        (() => {
            const bar = document.querySelector('.fi-bulk-bar').getBoundingClientRect();
            const tabBar = document.querySelector('.fi-bottom-nav').getBoundingClientRect();
            const more = document.querySelector('.fi-bulk-bar .fi-dropdown-trigger button').getBoundingClientRect();

            return bar.bottom <= tabBar.top
                && bar.left >= 0 && bar.right <= innerWidth
                && document.querySelector('.fi-bulk-bar').contains(document.elementFromPoint(more.left + more.width / 2, more.top + more.height / 2));
        })();
    JS;

    $page = visitAs($user, "/app/{$workspace->slug}/companies", 390, 664)
        ->assertVisible('.fi-ta-row >> nth=14');

    $page->script('window.scrollTo(0, document.documentElement.scrollHeight)');

    $page->click('.fi-ta-row >> nth=14 >> .fi-ta-record-checkbox')
        ->assertVisible('.fi-bulk-bar')
        ->assertScript($barClearOfTabBar, true)
        ->assertNoJavaScriptErrors();
});

it('keeps the last option of a long choice list inside the phone filter sheet', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Company::factory()->recycle([$user, $workspace])->create();

    resolve(CreateCustomField::class)->execute($user, [
        'entity_type' => 'company',
        'name' => 'Tier',
        'code' => 'tier',
        'type' => CustomFieldType::SELECT->value,
        'options' => array_map(static fn (int $number): array => ['name' => "Tier {$number}"], range(1, 14)),
    ]);

    $page = visitAs($user, "/app/{$workspace->slug}/companies", 390, 664)
        ->assertVisible('.fi-ta-row')
        ->click('[data-filter-add]')
        ->click('[data-pop-item][data-field="custom_fields.tier"]')
        ->click('[data-pop]:not([style*="display: none"]) [data-condition="is_any_of"]')
        ->assertVisible('[data-filter-option] >> nth=0')
        ->assertNoJavaScriptErrors();

    $lastOptionReachable = <<<'JS'
        (() => {
            const sheet = [...document.querySelectorAll('[data-filter-bar] [data-pop]')].find((pop) => pop.checkVisibility());
            const options = [...sheet.querySelectorAll('[data-filter-option]')];
            const last = options.at(-1);

            last.scrollIntoView({ block: 'nearest' });

            const box = last.getBoundingClientRect();
            const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

            return {
                listsEveryOption: options.length === 14,
                insideScreen: box.bottom <= innerHeight && box.top >= 0,
                reachable: last.contains(hit),
            };
        })();
    JS;

    expect($page->script($lastOptionReachable))->toBe(['listsEveryOption' => true, 'insideScreen' => true, 'reachable' => true]);
});
