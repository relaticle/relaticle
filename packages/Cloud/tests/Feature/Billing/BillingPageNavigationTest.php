<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Route;
use Relaticle\Cloud\Billing\Filament\Billing;
use Relaticle\Cloud\Billing\StripeBilling;

mutates(Billing::class, StripeBilling::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

/** @return list<string> */
function billingTabLabels(object $page): array
{
    return collect($page->getSubNavigation())
        ->filter(fn (NavigationItem $item): bool => $item->isVisible())
        ->map(fn (NavigationItem $item): string => $item->getLabel())
        ->values()
        ->all();
}

/** @return list<string> */
function billingActiveTabLabels(object $page): array
{
    return collect($page->getSubNavigation())
        ->filter(fn (NavigationItem $item): bool => $item->isActive())
        ->map(fn (NavigationItem $item): string => $item->getLabel())
        ->values()
        ->all();
}

test('the billing page renders the same tab strip as every workspace settings page', function (): void {
    withHostedBilling();

    $expected = [
        __('workspaces.tabs.general'),
        __('workspaces.tabs.members'),
        __('workspaces.tabs.custom_fields'),
        __('workspaces.tabs.email'),
        __('workspaces.tabs.import_history'),
        __('workspaces.tabs.activity'),
        __('workspaces.tabs.billing'),
    ];

    expect(billingTabLabels(resolve(Billing::class)))
        ->toBe($expected, '['.Billing::class.'] should render the full tab strip');
});

test('the billing page highlights only its own tab even when no page route is current', function (): void {
    withHostedBilling();

    expect(billingActiveTabLabels(resolve(Billing::class)))
        ->toBe([__('workspaces.tabs.billing')]);
});

test('billing keeps its own url so the paywall allowlist keeps matching', function (): void {
    expect(Billing::getSlug())->toBe('billing')
        ->and(Route::has('filament.app.pages.billing'))->toBeTrue();
});
