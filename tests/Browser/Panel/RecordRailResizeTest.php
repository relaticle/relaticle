<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;

beforeEach(function (): void {
    $this->withVite();

    $this->user = User::factory()->withWorkspace()->create();
    $this->workspace = $this->user->ownedWorkspaces()->first();
    $this->company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $this->person = People::factory()->recycle([$this->user, $this->workspace])->create();
});

function railWidth(): string
{
    return '(() => Math.round(document.querySelector(".fi-record-layout > :first-child").getBoundingClientRect().width))()';
}

it('resizes the details rail from its edge, keeps the width across record types, and resets on double click', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900)
        ->navigate("/app/{$this->workspace->slug}/companies/{$this->company->getKey()}")
        ->assertScript(railWidth(), 384)
        ->keys('.fi-record-rail-resize-handle', ['ArrowRight', 'ArrowRight'])
        ->assertScript(railWidth(), 416)
        ->assertScript('localStorage.getItem("record-rail-width")', '416')
        ->navigate("/app/{$this->workspace->slug}/people/{$this->person->getKey()}")
        ->assertScript(railWidth(), 416)
        ->drag('.fi-record-rail-resize-handle', '.fi-topbar .fi-user-menu-trigger')
        ->assertScript(railWidth(), 560);

    $page->script('document.querySelector(".fi-record-rail-resize-handle").dispatchEvent(new MouseEvent("dblclick", { bubbles: true }))');

    $page->assertScript(railWidth(), 384)
        ->assertScript('localStorage.getItem("record-rail-width")', null)
        ->assertNoJavaScriptErrors();
});

it('keeps the work pane at its minimum width and hides the handle once the rail stacks', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1280, 900);

    $page->script('localStorage.setItem("sidebar-width", "360"); localStorage.setItem("record-rail-width", "560")');

    $page->navigate("/app/{$this->workspace->slug}/companies/{$this->company->getKey()}")
        ->assertScript('Math.round(document.querySelector(".fi-record-pane").getBoundingClientRect().width)', 576)
        ->assertScript(railWidth(), 344)
        ->resize(1100, 900)
        ->assertScript('document.querySelector(".fi-record-rail-resize-handle").checkVisibility()', false)
        ->assertNoJavaScriptErrors();
});

it('jumps to the rail bounds with Home and End and ignores a stored width outside them', function (): void {
    $page = loginViaBrowser($this->user)
        ->assertPathIs("/app/{$this->workspace->slug}")
        ->resize(1440, 900);

    $page->script('localStorage.setItem("record-rail-width", "9999")');

    $page->navigate("/app/{$this->workspace->slug}/companies/{$this->company->getKey()}")
        ->assertScript(railWidth(), 384)
        ->assertScript('document.querySelector(".fi-record-rail-resize-handle").getAttribute("aria-valuenow")', '384')
        ->keys('.fi-record-rail-resize-handle', ['Home'])
        ->assertScript(railWidth(), 320)
        ->keys('.fi-record-rail-resize-handle', ['ArrowLeft'])
        ->assertScript(railWidth(), 320)
        ->keys('.fi-record-rail-resize-handle', ['End'])
        ->assertScript(railWidth(), 560)
        ->assertScript('localStorage.getItem("record-rail-width")', '560')
        ->assertNoJavaScriptErrors();
});
