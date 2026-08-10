<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Livewire\App\AppSidebar;
use App\Models\User;
use Filament\Facades\Filament;

mutates(AppSidebar::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->html = $this->get(Dashboard::getUrl(tenant: $this->team))->getContent();
});

it('renders the search row inside the sidebar, above the navigation', function (): void {
    $rowPosition = strpos($this->html, 'fi-sidebar-search-ctn');
    $sidebarPosition = strpos($this->html, 'fi-sidebar fi-main-sidebar');
    $navPosition = strpos($this->html, 'fi-sidebar-nav');

    expect($rowPosition)->not->toBeFalse()
        ->and($sidebarPosition)->toBeLessThan($rowPosition)
        ->and($rowPosition)->toBeLessThan($navPosition);
});

it('renders global search in that row rather than the topbar', function (): void {
    $rowPosition = strpos($this->html, 'fi-sidebar-search-ctn');
    $searchPosition = strpos($this->html, 'fi-global-search-ctn');
    $navPosition = strpos($this->html, 'fi-sidebar-nav');

    expect(substr_count($this->html, 'fi-global-search-ctn'))->toBe(1)
        ->and($rowPosition)->toBeLessThan($searchPosition)
        ->and($searchPosition)->toBeLessThan($navPosition);
});

it('renders exactly one notifications trigger, in that row', function (): void {
    expect(substr_count($this->html, 'fi-sidebar-notifications-btn"'))->toBe(1)
        ->and($this->html)->not->toContain('fi-topbar-database-notifications-btn')
        ->and($this->html)->not->toContain('fi-sidebar-database-notifications-btn');

    expect(strpos($this->html, 'fi-sidebar-notifications-btn'))
        ->toBeLessThan(strpos($this->html, 'fi-sidebar-nav'));
});

it('keeps database notifications enabled for the rest of the panel', function (): void {
    expect(Filament::getPanel('app')->hasDatabaseNotifications())->toBeTrue();
});

it('offers a keyboard shortcut hint on the search field', function (): void {
    expect(Filament::getPanel('app')->getGlobalSearchKeyBindings())->toContain('command+k', 'ctrl+k');
});
