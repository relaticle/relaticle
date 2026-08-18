<?php

declare(strict_types=1);

use App\Enums\TeamRole;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Filament\Pages\EditTeam;
use App\Filament\Pages\Team\CustomFields;
use App\Filament\Pages\Team\Members;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;

mutates(Members::class, CustomFields::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

/** @return list<string> */
function workspaceTabLabels(object $page): array
{
    return collect($page->getSubNavigation())
        ->filter(fn (NavigationItem $item): bool => $item->isVisible())
        ->map(fn (NavigationItem $item): string => $item->getLabel())
        ->values()
        ->all();
}

test('every workspace settings page renders the same tab strip', function (): void {
    Feature::define(BillingFeature::class, true);

    $expected = [
        __('teams.tabs.general'),
        __('teams.tabs.members'),
        __('teams.tabs.custom_fields'),
        __('teams.tabs.billing'),
    ];

    foreach ([EditTeam::class, Members::class, CustomFields::class, Billing::class] as $page) {
        expect(workspaceTabLabels(app($page)))
            ->toBe($expected, "[{$page}] should render the full tab strip");
    }
});

test('the custom fields tab lives under the team url and the standalone route is gone', function (): void {
    expect(CustomFields::getSlug())->toBe('team/custom-fields')
        ->and(Members::getSlug())->toBe('team/members')
        ->and(Route::has('filament.app.pages.custom-fields'))->toBeFalse()
        ->and(Route::has('filament.app.pages.team.custom-fields'))->toBeTrue();
});

test('billing keeps its own url so the paywall allowlist keeps matching', function (): void {
    expect(Billing::getSlug())->toBe('billing')
        ->and(Route::has('filament.app.pages.billing'))->toBeTrue();
});

test('a workspace admin can open every tab', function (): void {
    foreach ([
        EditTeam::getUrl(tenant: $this->team),
        Members::getUrl(tenant: $this->team),
        CustomFields::getUrl(tenant: $this->team),
    ] as $url) {
        $this->get($url)->assertSuccessful();
    }
});

test('a user outside the workspace cannot open the members tab', function (): void {
    $url = Members::getUrl(tenant: $this->team);

    // Filament's tenancy answers 404 rather than 403 so it does not leak that
    // the workspace exists.
    $this->actingAs(User::factory()->withTeam()->create())
        ->get($url)
        ->assertNotFound();
});

test('a team admin can open the members tab', function (): void {
    $admin = User::factory()->create();
    $this->team->users()->attach($admin, ['role' => TeamRole::Admin->value]);

    $this->actingAs($admin)
        ->get(Members::getUrl(tenant: $this->team))
        ->assertSuccessful();
});

test('a team editor cannot open the members tab', function (): void {
    $editor = User::factory()->create();
    $this->team->users()->attach($editor, ['role' => TeamRole::Editor->value]);

    $this->actingAs($editor)
        ->get(Members::getUrl(tenant: $this->team))
        ->assertForbidden();
});

test('the tab strip drops billing when the feature is off', function (): void {
    Feature::define(BillingFeature::class, false);

    expect(workspaceTabLabels(app(EditTeam::class)))
        ->not->toContain(__('teams.tabs.billing'));
});
