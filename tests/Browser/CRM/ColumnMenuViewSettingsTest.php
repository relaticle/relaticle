<?php

declare(strict_types=1);

use App\Filament\Tables\Concerns\HasColumnMenu;
use App\Models\Company;
use App\Models\User;
use Pest\Browser\Api\AwaitableWebpage;

mutates(HasColumnMenu::class);

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

it('keeps the view settings order in step with the headers', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('created_at'));
    $page->click(columnMenuItem('moveLeft'));
    $page->wait(1);
    openViewSettings($page);

    $headers = $page->script(headerOrder());

    expect($page->script(settingsOrder()))->toBe($headers);
    $page->assertNoJavaScriptErrors();
});

it('hides a column from view settings and shows it again from the add attribute panel', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    removeFromSettings($page, 'accountOwner.name')
        ->assertMissing(columnHeader('accountOwner.name'))
        ->assertMissing(settingsRow('accountOwner.name'))
        ->assertVisible('.fi-view-settings-panel');

    $page->click('[data-view-settings-add]')
        ->click('[aria-label="Show Account Owner"]')
        ->assertVisible(columnHeader('accountOwner.name'));

    waitUntil($page, headerOrder().'.at(-1) === "accountOwner.name"')
        ->assertScript(settingsOrder().'.at(-1)', 'accountOwner.name')
        ->assertNoJavaScriptErrors();
});

it('reorders a column by dragging it in view settings and keeps the order after a reload', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $order = $page->script(headerOrder());
    $moved = $order[3];
    $target = $order[1];

    $page->drag(settingsRow($moved, '.fi-view-settings-grip'), settingsRow($target, '.fi-view-settings-grip'));

    waitUntil($page, headerOrder().'.indexOf('.json_encode($moved).') < '.headerOrder().'.indexOf('.json_encode($target).')')
        ->assertScript(settingsOrder(), $page->script(headerOrder()))
        ->wait(1)
        ->refresh()
        ->assertPresent(columnHeader('created_at').' [data-column-menu-trigger]')
        ->assertScript(headerOrder().'.indexOf('.json_encode($moved).') < '.headerOrder().'.indexOf('.json_encode($target).')', true)
        ->assertNoJavaScriptErrors();
});

function companiesTableSort(): string
{
    return 'Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.tableSort';
}

function sortedCompaniesTable(object $test): AwaitableWebpage
{
    $page = openViewSettings(companiesTable($test->user, $test->workspace->slug));
    $page->script('Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.sortTable("name", "asc")');
    waitUntil($page, '('.companiesTableSort().') === "name:asc"');
    removeFromSettings($page, 'accountOwner.name')->assertMissing(columnHeader('accountOwner.name'));

    return $page;
}

it('reaches every row menu from the keyboard and returns focus to the trigger on Escape', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->keys('.fi-view-settings-trigger', 'Enter')->assertVisible('.fi-view-settings-panel');
    $page->keys('.fi-view-settings-trigger', 'Tab');

    foreach (range(1, 20) as $attempt) {
        if ($page->script('document.activeElement.matches("[data-view-settings-menu]") && !document.activeElement.closest("[data-column-locked]")') === true) {
            break;
        }

        $page->keys(':focus', 'Tab');
    }

    $column = $page->script('document.activeElement.closest("[data-column]").dataset.column');

    expect($column)->toBeString();

    $page->keys(':focus', 'Enter');
    waitUntil($page, 'document.activeElement.matches(`[data-view-settings-menu-item="label"]`)')
        ->assertVisible('.fi-view-settings-row-menu');

    $page->keys(':focus', 'ArrowDown');
    waitUntil($page, 'document.activeElement.matches(`[data-view-settings-menu-item="remove"]`)');

    $page->keys(':focus', 'Escape');
    waitUntil($page, sprintf('document.activeElement === document.querySelector(`%s`)', settingsRow($column, '[data-view-settings-menu]')))
        ->assertMissing('.fi-view-settings-row-menu')
        ->assertVisible('.fi-view-settings-panel')
        ->assertPresent(columnHeader($column));

    $page->keys(':focus', 'Enter');
    waitUntil($page, 'document.activeElement.matches(`[data-view-settings-menu-item="label"]`)');
    $page->keys(':focus', 'ArrowDown')->keys(':focus', 'Enter');
    waitUntil($page, sprintf('document.querySelector(`%s`) === null', columnHeader($column)));

    $page->keys(':focus', 'Escape');

    waitUntil($page, 'document.activeElement === document.querySelector(".fi-view-settings-trigger")')
        ->assertScript('document.querySelector(".fi-view-settings-panel").checkVisibility()', false)
        ->assertNoJavaScriptErrors();
});

it('focuses the first row when view settings opens from the keyboard and steps with the arrow keys', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->keys('.fi-view-settings-trigger', 'Enter')->assertVisible('.fi-view-settings-panel');
    $first = 'document.activeElement === document.querySelector(".fi-view-settings-row [data-view-settings-menu]")';
    waitUntil($page, $first);

    $page->keys(':focus', 'ArrowDown');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-menu]") && document.activeElement !== document.querySelector(".fi-view-settings-row [data-view-settings-menu]")');

    $page->keys(':focus', 'ArrowUp');
    waitUntil($page, $first);

    $page->keys(':focus', 'ArrowUp');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-add]")');

    $page->keys(':focus', 'Escape');
    waitUntil($page, 'document.activeElement === document.querySelector(".fi-view-settings-trigger")')
        ->assertNoJavaScriptErrors();
});

it('reaches add attribute from the keyboard', function (): void {
    $page = sortedCompaniesTable($this);
    $page->keys('.fi-view-settings-trigger', 'Tab');

    foreach (range(1, 40) as $attempt) {
        if ($page->script('document.activeElement.matches("[data-view-settings-add]")') === true) {
            break;
        }

        $page->keys(':focus', 'Tab');
    }

    $page->assertScript('document.activeElement.matches("[data-view-settings-add]")', true)
        ->keys(':focus', 'Enter');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-search]")');
    $page->keys(':focus', 'Escape');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-add]")');

    $page->assertNoJavaScriptErrors();
});

it('offers only a label change in the title column menu of view settings', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->click(settingsRow('name', '[data-view-settings-menu]'));
    waitUntil($page, 'document.querySelector(".fi-view-settings-row-menu").checkVisibility()')
        ->assertScript('[...document.querySelectorAll(".fi-view-settings-row-menu [role=menuitem]")].filter((item) => item.offsetParent !== null).map((item) => item.textContent.trim())', ['Edit column label'])
        ->assertNoJavaScriptErrors();
});

it('draws every view settings row with an icon and a menu button and no eye button', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->assertScript('[...document.querySelectorAll(".fi-view-settings-row")].every((row) => row.querySelector(".fi-view-settings-label svg") && row.querySelector("[data-view-settings-menu]"))', true)
        ->assertMissing('[data-view-settings-toggle]')
        ->assertNoJavaScriptErrors();
});

it('opens the row menu beside its button, aligned to its start edge and inside the viewport', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $trigger = settingsRow('created_at', '[data-view-settings-menu]');

    $page->click($trigger);
    waitUntil($page, 'document.querySelector(".fi-view-settings-row-menu").checkVisibility() && getComputedStyle(document.querySelector(".fi-view-settings-row-menu")).visibility === "visible"')
        ->assertScript('[...document.querySelectorAll(".fi-view-settings-row-menu [role=menuitem]")].filter((item) => item.offsetParent !== null).map((item) => item.textContent.trim())', ['Edit column label', 'Hide from view'])
        ->assertScript(sprintf('(() => { const t = document.querySelector(`%s`).getBoundingClientRect(); const m = document.querySelector(".fi-view-settings-row-menu").getBoundingClientRect(); return Math.abs(m.left - t.left) <= 1 && m.top >= t.bottom && m.right <= document.documentElement.clientWidth && m.bottom <= document.documentElement.clientHeight; })()', $trigger), true)
        ->assertScript(sprintf('document.querySelector(`%s`).getAttribute("aria-expanded")', $trigger), 'true')
        ->assertNoJavaScriptErrors();
});

it('removes a column from its row menu and offers it again in the add attribute panel', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    removeFromSettings($page, 'accountOwner.name')
        ->assertMissing(columnHeader('accountOwner.name'))
        ->assertMissing(settingsRow('accountOwner.name'))
        ->assertVisible('.fi-view-settings-panel');

    $page->click('[data-view-settings-add]')
        ->assertVisible('[aria-label="Show Account Owner"]')
        ->assertNoJavaScriptErrors();
});

it('changes a column label from its row menu and shows it in the row and the header', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->click(settingsRow('created_at', '[data-view-settings-menu]'))
        ->click(settingsMenuItem('label'))
        ->assertVisible('.fi-modal-window input')
        ->type('.fi-modal-window input', 'Added on')
        ->click('.fi-modal-window button[type="submit"]');

    waitUntil($page, sprintf('document.querySelector(`%s .fi-ta-header-cell-field`).textContent.trim() === "Added on"', columnHeader('created_at')))
        ->assertScript(sprintf('document.querySelector(`%s .fi-view-settings-label`).textContent.trim()', settingsRow('created_at')), 'Added on')
        ->assertNoJavaScriptErrors();
});

it('creates an attribute from the add panel and shows it as a column, with focus back on view settings', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->click('[data-view-settings-add]')
        ->assertVisible('[data-view-settings-create]')
        ->click('[data-view-settings-create]')
        ->assertVisible('.fi-modal-window input')
        ->type('.fi-modal-window input', 'Lead score')
        ->click('.fi-modal-window .fi-select-input-btn')
        ->assertScript('document.querySelectorAll(".fi-modal-window .fi-select-input-option .lucide").length > 10', true)
        ->click('.fi-modal-window .fi-select-input-option:nth-child(2)');

    $page->click('.fi-modal-window button[type="submit"]');

    waitUntil($page, 'document.querySelector(`th[x-data^="resizableColumn(\'custom_fields.lead_score\'"]`) !== null')
        ->assertScript(headerOrder().'.at(-1)', 'custom_fields.lead_score')
        ->assertScript('document.activeElement.matches(".fi-view-settings-trigger")', true)
        ->assertNoJavaScriptErrors();

    openViewSettings($page)
        ->assertScript(settingsOrder().'.at(-1)', 'custom_fields.lead_score')
        ->assertNoJavaScriptErrors();
});

it('shows the row menu button on every row without a hover, also on a phone', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(390, 844)->wait(0.5);

    openViewSettings($page)
        ->assertScript('[...document.querySelectorAll(".fi-view-settings-row [data-view-settings-menu]")].every((button) => button.checkVisibility({ opacityProperty: true }) && Number(getComputedStyle(button).opacity) > 0)', true)
        ->assertNoJavaScriptErrors();
});

it('darkens the row menu button and fills the row on hover', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $button = settingsRow('created_at', '[data-view-settings-menu]');
    $color = sprintf('getComputedStyle(document.querySelector(`%s`).querySelector(".fi-icon")).color', $button);
    $resting = $page->script($color);

    $page->hover(settingsRow('created_at', '.fi-view-settings-label'))
        ->assertScript(sprintf('getComputedStyle(document.querySelector(`%s`)).backgroundColor', settingsRow('created_at')), $page->script('(() => { const probe = document.createElement("div"); probe.style.backgroundColor = "var(--gray-50)"; document.body.append(probe); const color = getComputedStyle(probe).backgroundColor; probe.remove(); return color; })()'));

    expect($page->script($color))->not->toBe($resting);
});

it('draws the add panel items as tall as the view settings rows', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $rowHeight = $page->script('Math.round(document.querySelector(".fi-view-settings-row").getBoundingClientRect().height)');
    $page->click(settingsRow('created_at', '[data-view-settings-menu]'))
        ->click(settingsMenuItem('remove'))
        ->click('[data-view-settings-add]')
        ->assertVisible('[data-view-settings-add-item][data-attribute="created_at"]');

    $page->assertScript('Math.round(document.querySelector("[data-view-settings-add-item][data-attribute=created_at]").getBoundingClientRect().height)', $rowHeight)
        ->assertNoJavaScriptErrors();
});

it('clears the add panel search with Escape', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->click('[data-view-settings-add]')
        ->type('[data-view-settings-search]', 'zzz')
        ->keys('[data-view-settings-search]', 'Escape')
        ->assertScript('document.querySelector("[data-view-settings-search]").value', '')
        ->assertNoJavaScriptErrors();
});

it('offers the required type of a new attribute with no clear button and names the option input', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    $page->click('[data-view-settings-add]')
        ->click('[data-view-settings-create]')
        ->assertVisible('.fi-modal-window .fi-select-input-btn')
        ->assertMissing('.fi-modal-window .fi-select-input-value-remove-btn')
        ->click('.fi-modal-window .fi-select-input-btn')
        ->click('.fi-modal-window .fi-select-input-option[data-value="select"]')
        ->assertVisible('.fi-modal-window .fi-fo-repeater input')
        ->assertScript('document.querySelector(".fi-modal-window .fi-fo-repeater input").placeholder', 'Option name')
        ->assertNoJavaScriptErrors();
});

it('opens the attribute type list below its button on a phone', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(390, 844)->wait(0.5);

    openViewSettings($page)->click('[data-view-settings-add]')
        ->click('[data-view-settings-create]')
        ->assertVisible('.fi-modal-window .fi-select-input-btn')
        ->click('.fi-modal-window .fi-select-input-btn')
        ->assertVisible('.fi-modal-window .fi-select-input-option[data-value="text"]')
        ->assertScript('document.querySelector(".fi-modal-window .fi-select-input-option").closest("ul").getBoundingClientRect().top >= document.querySelector(".fi-modal-window .fi-select-input-btn").getBoundingClientRect().bottom', true)
        ->assertNoJavaScriptErrors();
});

it('never starts a drag from the row menu button', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $order = $page->script(settingsOrder());

    $page->drag(settingsRow($order[3], '[data-view-settings-menu]'), settingsRow($order[1], '.fi-view-settings-grip'));
    $page->wait(1)->assertScript(settingsOrder(), $order)
        ->assertScript(headerOrder(), $order)
        ->assertNoJavaScriptErrors();
});

it('opens view settings as a sheet on a phone without overflowing the header', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(390, 844)->wait(0.5);

    $page->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertScript('document.querySelector(".fi-header").scrollWidth <= document.querySelector(".fi-header").clientWidth', true)
        ->assertScript('document.querySelector(".fi-view-settings-trigger").getBoundingClientRect().right <= document.documentElement.clientWidth', true);

    openViewSettings($page)
        ->assertScript('getComputedStyle(document.querySelector(".fi-view-settings-panel").closest(".fi-dropdown-panel")).position', 'fixed')
        ->assertScript('Math.round(document.querySelector(".fi-view-settings-panel").closest(".fi-dropdown-panel").getBoundingClientRect().bottom) === window.innerHeight', true)
        ->assertNoJavaScriptErrors();
});
