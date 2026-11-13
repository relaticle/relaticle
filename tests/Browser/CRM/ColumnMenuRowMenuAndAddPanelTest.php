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

function rowMenuOpen(): string
{
    return '(() => { const menu = document.querySelector(".fi-view-settings-row-menu"); return menu.checkVisibility() && getComputedStyle(menu).visibility === "visible"; })()';
}

function openRowMenu(AwaitableWebpage $page, string $column): AwaitableWebpage
{
    $page->click(settingsRow($column, '[data-view-settings-menu]'));

    return waitUntil($page, rowMenuOpen().' && document.activeElement.matches(".fi-view-settings-row-menu [role=menuitem]")');
}

it('closes the row menu when the list scrolls or the window resizes', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(1440, 360);
    openViewSettings($page);
    $order = $page->script(settingsOrder());

    openRowMenu($page, $order[1]);
    $page->script('document.querySelector(".fi-view-settings-scroll").scrollTop = 40');
    waitUntil($page, '!('.rowMenuOpen().')');

    openRowMenu($page, $order[1])->resize(1300, 360);
    waitUntil($page, '!('.rowMenuOpen().')')->assertNoJavaScriptErrors();
});

it('closes the row menu on Tab and Shift+Tab and returns focus to its button', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $trigger = settingsRow('created_at', '[data-view-settings-menu]');
    $focusedTrigger = sprintf('document.activeElement === document.querySelector(`%s`)', $trigger);

    foreach (['Tab', 'Shift+Tab'] as $key) {
        openRowMenu($page, 'created_at')->keys(':focus', $key);
        waitUntil($page, '!('.rowMenuOpen().') && '.$focusedTrigger);
    }

    $page->assertVisible('.fi-view-settings-panel')->assertNoJavaScriptErrors();
});

it('moves through the row menu with Home and End', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openRowMenu($page, 'created_at')->keys(':focus', 'End');
    waitUntil($page, 'document.activeElement.matches(`[data-view-settings-menu-item="remove"]`)');
    $page->keys(':focus', 'Home');
    waitUntil($page, 'document.activeElement.matches(`[data-view-settings-menu-item="label"]`)')->assertNoJavaScriptErrors();
});

it('keeps focus in the table after the label modal closes', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openRowMenu($page, 'created_at')->click(settingsMenuItem('label'))->assertVisible('.fi-modal-window input');
    $page->keys('.fi-modal-window input', 'Escape');

    waitUntil($page, 'document.activeElement.matches(".fi-view-settings-trigger, .fi-view-settings-trigger *")')->assertNoJavaScriptErrors();
});

it('returns focus to the column header after its label modal closes or saves', function (string $key): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    $page->script(openColumnMenu('created_at'));
    waitUntil($page, menuItemFocused())->click(columnMenuItem('editLabel'))->assertVisible('.fi-modal-window input');
    $page->keys('.fi-modal-window input', $key);

    waitUntil($page, sprintf('!document.querySelector(".fi-modal-open") && document.activeElement === document.querySelector(`%s [data-column-menu-trigger]`)', columnHeader('created_at')))->assertNoJavaScriptErrors();
})->with(['Escape', 'Enter']);

it('closes the row menu when a grip is dragged', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $order = $page->script(settingsOrder());

    openRowMenu($page, $order[1])->drag(settingsRow($order[3], '.fi-view-settings-grip'), settingsRow($order[2], '.fi-view-settings-grip'));

    waitUntil($page, '!('.rowMenuOpen().')')->assertNoJavaScriptErrors();
});

it('keeps the row of the open menu highlighted and ties the button to the menu', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openRowMenu($page, 'created_at')->hover('[data-view-settings-menu-item="label"]');

    $page->assertScript(sprintf('!document.querySelector(`%s`).matches(":hover") && getComputedStyle(document.querySelector(`%s`)).backgroundColor !== "rgba(0, 0, 0, 0)"', settingsRow('created_at'), settingsRow('created_at')), true)
        ->assertScript(sprintf('document.querySelector(`%s`).getAttribute("aria-controls") === document.querySelector(".fi-view-settings-row-menu").id', settingsRow('created_at', '[data-view-settings-menu]')), true)
        ->assertNoJavaScriptErrors();
});

it('focuses the neighbouring row button after Hide from view', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $order = $page->script(settingsOrder());

    openRowMenu($page, $order[2])->click(settingsMenuItem('remove'));

    waitUntil($page, sprintf('document.activeElement === document.querySelector(`%s`)', settingsRow($order[3], '[data-view-settings-menu]')))->assertNoJavaScriptErrors();
});

it('opens the row menu above its button near the bottom of the viewport', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug)->resize(1440, 400));
    $last = $page->script(settingsOrder().'.at(-1)');

    $page->script('document.querySelectorAll(".fi-view-settings-footer").forEach((footer) => footer.remove()); document.querySelector(".fi-view-settings-scroll").scrollTop = 10000');
    openRowMenu($page, $last);

    waitUntil($page, sprintf('(() => { const menu = document.querySelector(".fi-view-settings-row-menu").getBoundingClientRect(); const button = document.querySelector(`%s`).getBoundingClientRect(); return button.bottom + 4 + menu.height > document.documentElement.clientHeight - 8 && menu.bottom <= button.top && menu.top >= 0; })()', settingsRow($last, '[data-view-settings-menu]')))
        ->assertNoJavaScriptErrors();
});

function addPanelOpen(): string
{
    return 'document.querySelector("[data-view-settings-add-panel]").checkVisibility()';
}

function addItems(): string
{
    return '[...document.querySelectorAll("[data-view-settings-add-item]")].filter((item) => item.checkVisibility()).map((item) => item.dataset.attribute)';
}

function openAddPanel(AwaitableWebpage $page): AwaitableWebpage
{
    $page->click('[data-view-settings-add]');

    return waitUntil($page, addPanelOpen().' && document.activeElement.matches("[data-view-settings-search]")');
}

function openAddPanelWithoutFocus(AwaitableWebpage $page): AwaitableWebpage
{
    $page->click('[data-view-settings-add]');

    return waitUntil($page, addPanelOpen());
}

function noMatchNoteVisible(): string
{
    return '[...document.querySelectorAll("[data-view-settings-add-panel] .fi-view-settings-note")].some((note) => note.checkVisibility())';
}

function hideColumns(AwaitableWebpage $page, array $columns): AwaitableWebpage
{
    foreach ($columns as $column) {
        removeFromSettings($page, $column)->assertMissing(settingsRow($column));
    }

    return $page;
}

it('opens the add attribute panel in place of the list with a focused search and every hidden attribute', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));
    $width = $page->script('document.querySelector(".fi-view-settings-panel").getBoundingClientRect().width');

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page)
        ->assertScript('document.activeElement.placeholder', 'Search company attributes…')
        ->assertScript('document.querySelector("[data-view-settings-back]").checkVisibility()', true)
        ->assertScript('document.querySelector(".fi-view-settings-list").checkVisibility()', false)
        ->assertScript('document.querySelector(".fi-view-settings-panel").getBoundingClientRect().width', $width)
        ->assertScript('["accountOwner.name", "created_at"].every((attribute) => '.addItems().'.includes(attribute))', true)
        ->assertScript('[...document.querySelectorAll("[data-view-settings-add-item]")].every((item) => item.querySelector(".fi-view-settings-label svg"))', true)
        ->assertScript('document.querySelector("[data-view-settings-add-item][data-attribute=name]")', null)
        ->assertScript(noMatchNoteVisible(), false);

    $page->type('[data-view-settings-search]', 'zzzz-no-match');

    waitUntil($page, noMatchNoteVisible())
        ->assertNoJavaScriptErrors();
});

it('narrows the add attribute list as the member types, in any case and anywhere in the label', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page);
    $label = $page->script('document.querySelector("[data-view-settings-add-item][data-attribute=\'created_at\']").dataset.text');
    $needle = strtoupper(substr($label, 1, 3));

    $page->type('[data-view-settings-search]', $needle);

    waitUntil($page, addItems().'.includes("created_at")')
        ->assertScript(addItems().'.every((attribute) => document.querySelector(`[data-attribute="${attribute}"]`).dataset.text.toLowerCase().includes('.json_encode(strtolower($needle)).'))', true)
        ->type('[data-view-settings-search]', 'zzzz-no-match');

    waitUntil($page, addItems().'.length === 0')
        ->assertSee('No attribute matches your search')
        ->assertNoJavaScriptErrors();
});

it('moves through the add attribute results with the arrow keys and picks with Enter', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page);
    $items = $page->script(addItems());

    $page->keys(':focus', 'ArrowDown');
    waitUntil($page, sprintf('document.activeElement.dataset.attribute === %s', json_encode($items[0])));
    $page->keys(':focus', 'ArrowDown');
    waitUntil($page, sprintf('document.activeElement.dataset.attribute === %s', json_encode($items[1])));
    $page->keys(':focus', 'ArrowUp')->keys(':focus', 'ArrowUp');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-search]")');
    $page->keys(':focus', 'ArrowDown')->keys(':focus', 'ArrowDown')->keys(':focus', 'Enter');

    waitUntil($page, sprintf('document.querySelector(`%s`) !== null', columnHeader($items[1])))
        ->assertMissing(columnHeader($items[0]))
        ->assertNoJavaScriptErrors();
});

it('picks the first match with Enter from the search input and keeps the panel open with the search', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page);
    $first = $page->script(addItems())[0];
    $text = $page->script(sprintf('document.querySelector(`[data-attribute="%s"]`).dataset.text', $first));
    $term = substr($text, 0, 2);

    $page->type('[data-view-settings-search]', $term)->keys('[data-view-settings-search]', 'Enter');

    waitUntil($page, sprintf('document.querySelector(`%s`) !== null', columnHeader($first)))
        ->assertScript(addPanelOpen(), true)
        ->assertScript('document.querySelector("[data-view-settings-search]").value', $term)
        ->assertScript('document.activeElement.matches("[data-view-settings-search]")', true)
        ->assertScript(addItems().'.includes('.json_encode($first).')', false);

    waitUntil($page, headerOrder().'.at(-1) === '.json_encode($first))
        ->assertNoJavaScriptErrors();
});

it('shows the picked attribute at the end of the list when the member goes back', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page)->click('[data-view-settings-add-item][data-attribute="accountOwner.name"]');

    waitUntil($page, headerOrder().'.at(-1) === "accountOwner.name"')
        ->assertScript(addPanelOpen(), true)
        ->assertScript(addItems().'.includes("created_at") && !'.addItems().'.includes("accountOwner.name")', true);

    $page->click('[data-view-settings-back]');

    waitUntil($page, 'document.activeElement.matches("[data-view-settings-add]")')
        ->assertScript(addPanelOpen(), false)
        ->assertScript(settingsOrder().'.at(-1)', 'accountOwner.name')
        ->assertNoJavaScriptErrors();
});

it('says every attribute is in the view when none is hidden', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openAddPanel($page);

    $headers = count($page->script(headerOrder()));

    for ($remaining = count($page->script(addItems())); $remaining > 0; $remaining--) {
        $page->keys('[data-view-settings-search]', 'Enter');
        waitUntil($page, headerOrder().'.length === '.++$headers);
    }

    $page->assertSee('Every attribute is already in this view')
        ->assertScript(addItems(), [])
        ->assertNoJavaScriptErrors();
});

it('goes back to the list on Escape and closes view settings on a second Escape', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openAddPanel($page)->keys(':focus', 'Escape');

    waitUntil($page, 'document.activeElement.matches("[data-view-settings-add]")')
        ->assertScript(addPanelOpen(), false)
        ->assertVisible('.fi-view-settings-panel')
        ->assertVisible('[data-view-settings-add]');

    $page->keys(':focus', 'Escape');

    waitUntil($page, 'document.activeElement === document.querySelector(".fi-view-settings-trigger")')
        ->assertScript('document.querySelector(".fi-view-settings-panel").checkVisibility()', false)
        ->assertNoJavaScriptErrors();
});

it('reopens view settings on the list', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openAddPanel($page)->type('[data-view-settings-search]', 'abc')->keys(':focus', 'Escape');
    waitUntil($page, 'document.activeElement.matches("[data-view-settings-add]")')->keys(':focus', 'Escape');
    waitUntil($page, 'document.activeElement === document.querySelector(".fi-view-settings-trigger")');

    $page->click('.fi-view-settings-trigger');

    waitUntil($page, 'document.querySelector(".fi-view-settings-list").checkVisibility()')
        ->assertScript(addPanelOpen(), false)
        ->assertNoJavaScriptErrors();
});

it('reopens view settings on the list after closing it from the add panel', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    openAddPanel($page)->type('[data-view-settings-search]', 'abc')->click('.fi-view-settings-trigger');
    waitUntil($page, 'document.querySelector(".fi-view-settings-panel").checkVisibility() === false');

    $page->click('.fi-view-settings-trigger');

    waitUntil($page, 'document.querySelector(".fi-view-settings-list").checkVisibility()')
        ->assertScript(addPanelOpen(), false)
        ->assertScript('document.querySelector("[data-view-settings-search]").value', '')
        ->assertNoJavaScriptErrors();
});

it('picks a column once however fast the member presses Enter', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page);
    $text = $page->script('document.querySelector("[data-view-settings-add-item][data-attribute=\'created_at\']").dataset.text');

    $page->type('[data-view-settings-search]', $text);
    waitUntil($page, addItems().'.length === 1');
    $page->script('(async () => { const input = document.querySelector("[data-view-settings-search]"); for (let i = 0; i < 2; i++) { input.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true })); await new Promise((resolve) => setTimeout(resolve, 15)); } })()');

    waitUntil($page, sprintf('document.querySelector(`%s`) !== null', columnHeader('created_at')));
    $page->wait(1.5)
        ->assertPresent(columnHeader('created_at'))
        ->assertScript(headerOrder().'.filter((column) => column === "created_at").length', 1)
        ->assertNoJavaScriptErrors();
});

it('ignores a second Enter while the first pick is still landing', function (): void {
    $page = openViewSettings(companiesTable($this->user, $this->workspace->slug));

    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanel($page);
    waitUntil($page, addItems().'.length >= 2');
    $count = $page->script(addItems().'.length');
    $first = $page->script(addItems().'[0]');
    $second = $page->script(addItems().'[1]');

    $page->script('(async () => { const input = document.querySelector("[data-view-settings-search]"); for (let i = 0; i < 2; i++) { input.dispatchEvent(new KeyboardEvent("keydown", { key: "Enter", bubbles: true, cancelable: true })); await new Promise((resolve) => setTimeout(resolve, 15)); } })()');

    waitUntil($page, sprintf('document.querySelector(`%s`) !== null', columnHeader($first)));
    $page->wait(1.5)
        ->assertNotPresent(columnHeader($second))
        ->assertScript(addItems().'.length', $count - 1)
        ->assertNoJavaScriptErrors();
});

it('leaves the search unfocused on a phone, at 16px, and after a pick', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug)->resize(390, 700)->wait(0.5);

    openViewSettings($page);
    hideColumns($page, ['accountOwner.name', 'created_at']);
    openAddPanelWithoutFocus($page)->wait(0.5)
        ->assertScript('document.activeElement.matches("[data-view-settings-search]")', false)
        ->assertScript('getComputedStyle(document.querySelector("[data-view-settings-search]")).fontSize', '16px');

    $page->click('[data-view-settings-add-item][data-attribute="created_at"]');
    waitUntil($page, sprintf('document.querySelector(`%s`) !== null', columnHeader('created_at')));
    $page->wait(0.5)->assertScript('document.activeElement.matches("[data-view-settings-search]")', false)
        ->assertNoJavaScriptErrors();
});

it('scrolls the add attribute list on a phone and keeps the search on screen', function (int $width): void {
    $page = companiesTable($this->user, $this->workspace->slug);

    openViewSettings($page);
    $columns = array_slice($page->script('[...document.querySelectorAll(".fi-view-settings-row:not([data-column-locked])")].map((row) => row.dataset.column)'), 0, 6);
    hideColumns($page, $columns);
    $page->resize($width, 460)->wait(0.5);

    if ($page->script('document.querySelector(".fi-view-settings-panel").checkVisibility()') !== true) {
        openViewSettings($page);
    }

    openAddPanelWithoutFocus($page)
        ->assertScript('(() => { const r = document.querySelector("[data-view-settings-add-panel]").getBoundingClientRect(); return r.left >= 0 && r.right <= document.documentElement.clientWidth && r.bottom <= window.innerHeight; })()', true)
        ->assertScript('(() => { const list = document.querySelector("[data-view-settings-add-panel] .fi-view-settings-scroll"); return list.scrollHeight > list.clientHeight; })()', true);

    $page->script('(() => { const list = document.querySelector("[data-view-settings-add-panel] .fi-view-settings-scroll"); list.scrollTop = list.scrollHeight; })()');

    $page->assertScript('(() => { const r = document.querySelector("[data-view-settings-search]").getBoundingClientRect(); return r.top >= 0 && r.bottom <= window.innerHeight; })()', true)
        ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth', true)
        ->assertNoJavaScriptErrors();
})->with([390, 360]);
