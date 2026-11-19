<?php

declare(strict_types=1);

use App\Filament\Concerns\HasCustomFieldColumns;
use App\Models\Company;
use App\Models\User;

pest()->group('webkit');

mutates(HasCustomFieldColumns::class);

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
});

it('closes the Add column menu once a hidden attribute is picked, shows its column and focuses the button', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $page->script('document.querySelector("[data-add-column-trigger]").click()');
    waitUntil($page, 'document.querySelector("#add-column-menu").checkVisibility()');

    $attribute = $page->script('document.querySelector("[data-add-column-item]").dataset.attribute');
    $page->script("document.querySelector('[data-add-column-item][data-attribute=\"{$attribute}\"]').click()");

    waitUntil($page, sprintf(
        '!document.querySelector("#add-column-menu").checkVisibility() && %s.includes("%s") && document.activeElement.matches("[data-add-column-trigger]")',
        headerOrder(),
        $attribute,
    ));
});

it('shows the attribute the member picks after an earlier pick drew the list again', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $open = 'document.querySelector("[data-add-column-trigger]").click()';
    $page->script($open);
    waitUntil($page, 'document.querySelector("#add-column-menu").checkVisibility()');

    [$first, $second] = $page->script('[...document.querySelectorAll("[data-add-column-item]")].slice(0, 2).map((item) => item.dataset.attribute)');
    $page->script("document.querySelector('[data-add-column-item][data-attribute=\"{$first}\"]').click()");
    waitUntil($page, sprintf('%s.includes("%s") && document.activeElement.matches("[data-add-column-trigger]")', headerOrder(), $first));
    $page->wait(0.3);

    $page->script($open);
    waitUntil($page, 'document.querySelector("#add-column-menu").checkVisibility()');
    $page->script('[...document.querySelectorAll("[data-add-column-item]")].find((item) => item.checkVisibility()).click()');

    waitUntil($page, sprintf('%1$s.includes("%2$s") && %1$s.includes("%3$s")', headerOrder(), $first, $second));
});

it('focuses the search box when the Add column menu opens, so Escape closes it from the keyboard', function (): void {
    $page = companiesTable($this->user, $this->workspace->slug);
    $page->script('document.querySelector("[data-add-column-trigger]").click()');

    waitUntil($page, 'document.activeElement.matches("[data-add-column-search]")');

    $page->keys('[data-add-column-search]', 'Escape');

    waitUntil($page, '!document.querySelector("#add-column-menu").checkVisibility() && document.activeElement.matches("[data-add-column-trigger]")');
});

it('opens the Add column menu as wide as View settings', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900);
    $page->click('.fi-view-settings-trigger')->assertVisible('.fi-view-settings-panel');
    $viewSettingsWidth = $page->script('document.querySelector(".fi-view-settings-panel").closest(".fi-dropdown-panel").getBoundingClientRect().width');
    $page->keys('.fi-view-settings-trigger', 'Escape');

    $page->script('document.querySelector("[data-add-column-trigger]").click()');
    waitUntil($page, 'document.querySelector("#add-column-menu").checkVisibility()');

    expect($page->script('document.querySelector("#add-column-menu").getBoundingClientRect().width'))->toBe($viewSettingsWidth);
});

it('opens the create attribute form on the type picked from the Add column menu', function (): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", 1440, 900);
    $page->script('document.querySelector("[data-add-column-trigger]").click()');
    waitUntil($page, 'document.querySelector("#add-column-menu").checkVisibility()');

    $page->script('document.querySelector("[data-add-column-create]").click()');
    waitUntil($page, 'document.querySelector("[data-add-column-type][data-type=\"number\"]").checkVisibility()');
    $page->script('document.querySelector("[data-add-column-type][data-type=\"number\"]").click()');

    waitUntil($page, '!document.querySelector("#add-column-menu").checkVisibility() && Livewire.all().find((component) => component.name.includes("ListCompanies")).$wire.mountedActions?.[0]?.data?.type === "number"');
});

it('opens the Add column menu under its button, inside the window', function (int $width): void {
    $page = visitAs($this->user, "/app/{$this->workspace->slug}/companies", $width, 900);
    $page->script('document.querySelector("[data-add-column-trigger]").click()');
    waitUntil($page, 'document.querySelector("#add-column-menu").checkVisibility()');

    $placement = $page->wait(0.3)->script('(() => { const button = document.querySelector("[data-add-column-trigger]").getBoundingClientRect(); const menu = document.querySelector("#add-column-menu").getBoundingClientRect(); return { below: menu.top >= button.bottom && menu.top - button.bottom <= 8, inside: menu.left >= 0 && menu.right <= document.documentElement.clientWidth } })()');

    expect($placement)->toBe(['below' => true, 'inside' => true]);
})->with([1920, 1280]);
