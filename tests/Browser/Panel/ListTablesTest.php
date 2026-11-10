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

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$workspace->slug}/{$path}")
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
    'opportunities' => ['opportunities', 'custom-fields.amount', false],
    'import-history' => ['import-history', 'status', true],
]);

it('shows filament\'s toolbar row only while a record is selected', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/companies")
        ->assertVisible('[data-filter-bar]')
        ->assertScript('document.querySelector(".fi-ta-header-toolbar").offsetParent === null', true)
        ->click('.fi-ta-record-checkbox')
        ->assertVisible('.fi-ta-header-toolbar')
        ->assertNoJavaScriptErrors();
});

it('keeps the list table header row on desktop', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$workspace->slug}/tasks")
        ->assertVisible('.fi-ta-header-cell.fi-ta-header-cell-title')
        ->assertScript(CELL_LABELS_SHOWN, false)
        ->assertNoJavaScriptErrors();
});

it('keeps a linked company chip inside its cell link', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    seedListRows($user);

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->navigate("/app/{$workspace->slug}/people")
        ->assertScript('document.querySelector(\'[class~="fi-ta-cell-company.name"] a.fi-ta-col .fi-record-chip\') !== null', true)
        ->assertNoJavaScriptErrors();
});

it('keeps a long record name inside its phone card, clear of the row menu', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Company::factory()->recycle([$user, $workspace])->create(['name' => 'Long Name Industries International Holdings Limited']);

    $titleClearOfMenu = <<<'JS'
        (() => {
            const row = document.querySelector('.fi-ta-row');
            const range = document.createRange();
            range.selectNodeContents(row.querySelector('.fi-ta-cell-content .fi-record-chip, .fi-ta-cell-content .fi-ta-text-item'));

            return Math.ceil(range.getBoundingClientRect().right) <= Math.floor(row.querySelector('.fi-ta-actions button').getBoundingClientRect().left);
        })();
    JS;

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(320, 568)
        ->navigate("/app/{$workspace->slug}/companies")
        ->assertVisible('.fi-ta-row')
        ->assertScript($titleClearOfMenu, true)
        ->assertNoJavaScriptErrors();
});

it('hides a column set visibleFrom sm in a phone card and shows it from sm up', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Company::factory()->recycle([$user, $workspace])->create();

    $createdAtDisplayed = 'getComputedStyle(document.querySelector(\'.fi-ta-row [class~="fi-ta-cell-created-at"]\')).display !== "none"';

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$workspace->slug}/companies")
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

    loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 844)
        ->navigate("/app/{$workspace->slug}/people")
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

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 664)
        ->navigate("/app/{$workspace->slug}/companies")
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

it('keeps the toolbar with the bulk actions in view on a phone while rows are ticked', function (): void {
    $this->withVite();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    Company::factory()->count(15)->recycle([$user, $workspace])->create();

    $toolbarGap = <<<'JS'
        (() => {
            const toolbar = document.querySelector('.fi-ta-header-ctn').getBoundingClientRect();
            const topbar = document.querySelector('.fi-topbar-ctn').getBoundingClientRect();

            return Math.round(toolbar.top - topbar.bottom);
        })();
    JS;

    $bulkTriggerHit = <<<'JS'
        (() => {
            const trigger = [...document.querySelectorAll('.fi-ta-header-ctn button')]
                .find((button) => button.checkVisibility() && (button.getAttribute('title') ?? button.getAttribute('aria-label') ?? '').includes('Bulk'));
            const box = trigger.getBoundingClientRect();

            return trigger.contains(document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2));
        })();
    JS;

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 664)
        ->navigate("/app/{$workspace->slug}/companies")
        ->assertVisible('.fi-ta-row >> nth=14');

    $page->script('window.scrollTo(0, document.documentElement.scrollHeight)');

    expect($page->script($toolbarGap))->toBeLessThan(0);

    $page->click('.fi-ta-row >> nth=14 >> .fi-ta-record-checkbox');

    expect($page->script($toolbarGap))->toBe(0)
        ->and($page->script($bulkTriggerHit))->toBeTrue();
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

    $page = loginViaBrowser($user)
        ->assertPathIs("/app/{$workspace->slug}")
        ->resize(390, 664)
        ->navigate("/app/{$workspace->slug}/companies")
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
