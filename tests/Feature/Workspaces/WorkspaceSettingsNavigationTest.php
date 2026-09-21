<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Features\Billing as BillingFeature;
use App\Filament\Pages\Billing;
use App\Filament\Pages\EditWorkspace;
use App\Filament\Pages\Workspace\ActivityLog;
use App\Filament\Pages\Workspace\CustomFields;
use App\Filament\Pages\Workspace\Members;
use App\Models\User;
use App\Providers\Filament\AppPanelProvider;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Navigation\NavigationItem;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;
use Relaticle\ImportWizard\Filament\Pages\ImportHistory;

mutates(AppPanelProvider::class, Members::class, CustomFields::class, EmailAccountsPage::class, EmailPrivacySettingsPage::class, ActivityLog::class, ImportHistory::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
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

/** @return list<string> */
function activeWorkspaceTabLabels(object $page): array
{
    return collect($page->getSubNavigation())
        ->filter(fn (NavigationItem $item): bool => $item->isActive())
        ->map(fn (NavigationItem $item): string => $item->getLabel())
        ->values()
        ->all();
}

test('every workspace settings page renders the same tab strip', function (): void {
    Feature::define(BillingFeature::class, true);

    $expected = [
        __('workspaces.tabs.general'),
        __('workspaces.tabs.members'),
        __('workspaces.tabs.custom_fields'),
        __('workspaces.tabs.email'),
        __('workspaces.tabs.import_history'),
        __('workspaces.tabs.activity'),
        __('workspaces.tabs.billing'),
    ];

    foreach ([EditWorkspace::class, Members::class, CustomFields::class, EmailAccountsPage::class, ManageEmailTemplates::class, EmailPrivacySettingsPage::class, ImportHistory::class, ActivityLog::class, Billing::class] as $page) {
        expect(workspaceTabLabels(resolve($page)))
            ->toBe($expected, "[{$page}] should render the full tab strip");
    }
});

test('workspace settings tabs live under the workspace url without duplicate standalone routes', function (): void {
    expect(CustomFields::getSlug())->toBe('workspace/custom-fields')
        ->and(Members::getSlug())->toBe('workspace/members')
        ->and(EmailAccountsPage::getSlug())->toBe('workspace/email')
        ->and(EmailTemplateResource::getSlug())->toBe('workspace/email/templates')
        ->and(EmailPrivacySettingsPage::getSlug())->toBe('workspace/email/privacy')
        ->and(ActivityLog::getSlug())->toBe('workspace/activity')
        ->and(Route::has('filament.app.pages.custom-fields'))->toBeFalse()
        ->and(Route::has('filament.app.pages.workspace.custom-fields'))->toBeTrue()
        ->and(Route::has('filament.app.email-settings.pages.accounts'))->toBeFalse()
        ->and(Route::has('filament.app.pages.workspace.email'))->toBeTrue();
});

test('billing keeps its own url so the paywall allowlist keeps matching', function (): void {
    expect(Billing::getSlug())->toBe('billing')
        ->and(Route::has('filament.app.pages.billing'))->toBeTrue();
});

test('a workspace admin can open every tab', function (): void {
    foreach ([
        EditWorkspace::getUrl(tenant: $this->workspace),
        Members::getUrl(tenant: $this->workspace),
        CustomFields::getUrl(tenant: $this->workspace),
        EmailAccountsPage::getUrl(tenant: $this->workspace),
        EmailTemplateResource::getUrl(tenant: $this->workspace),
        EmailPrivacySettingsPage::getUrl(tenant: $this->workspace),
        ImportHistory::getUrl(tenant: $this->workspace),
        ActivityLog::getUrl(tenant: $this->workspace),
    ] as $url) {
        $this->get($url)->assertSuccessful();
    }
});

test('a user outside the workspace cannot open the members tab', function (): void {
    $url = Members::getUrl(tenant: $this->workspace);

    // Filament's tenancy answers 404 rather than 403 so it does not leak that
    // the workspace exists.
    $this->actingAs(User::factory()->withWorkspace()->create())
        ->get($url)
        ->assertNotFound();
});

test('the tab strip hides admin-only tabs from members without the admin role', function (): void {
    Feature::define(BillingFeature::class, true);

    $editor = User::factory()->create();
    $this->workspace->users()->attach($editor, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($editor);

    expect(workspaceTabLabels(resolve(EditWorkspace::class)))
        ->not->toContain(__('workspaces.tabs.activity'))
        ->and(workspaceTabLabels(resolve(ImportHistory::class)))
        ->toContain(__('workspaces.tabs.import_history'))
        ->toContain(__('workspaces.tabs.billing'))
        ->not->toContain(__('workspaces.tabs.activity'));
});

test('a workspace editor reaches their email accounts and templates but not the workspace email privacy', function (): void {
    $editor = User::factory()->create();
    $this->workspace->users()->attach($editor, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($editor);

    expect(workspaceTabLabels(resolve(EditWorkspace::class)))->toContain(__('workspaces.tabs.email'));

    $this->get(EmailAccountsPage::getUrl(tenant: $this->workspace))
        ->assertSuccessful()
        ->assertSee(__('filament/resources/email-template.navigation_label'))
        ->assertDontSee(__('filament/pages/email-privacy-settings.tabs.sharing'));

    $this->get(EmailTemplateResource::getUrl(tenant: $this->workspace))->assertSuccessful();

    $this->get(EmailPrivacySettingsPage::getUrl(tenant: $this->workspace))->assertForbidden();
});

test('a workspace admin sees the workspace email privacy tabs next to accounts and templates', function (): void {
    $this->get(EmailAccountsPage::getUrl(tenant: $this->workspace))
        ->assertSuccessful()
        ->assertSeeInOrder([
            __('filament/pages/email-accounts.navigation_label'),
            __('filament/resources/email-template.navigation_label'),
            __('filament/pages/email-privacy-settings.tabs.visibility'),
            __('filament/pages/email-privacy-settings.tabs.sharing'),
            __('filament/pages/email-privacy-settings.tabs.record_creation'),
        ])
        ->assertSee(EmailPrivacySettingsPage::getUrl(['tab' => 'sharing'], tenant: $this->workspace), escape: false);
});

test('a workspace admin can open the members tab', function (): void {
    $admin = User::factory()->create();
    $this->workspace->users()->attach($admin, ['role' => WorkspaceRole::Admin->value]);

    $this->actingAs($admin)
        ->get(Members::getUrl(tenant: $this->workspace))
        ->assertSuccessful();
});

test('a workspace editor cannot open the members tab', function (): void {
    $editor = User::factory()->create();
    $this->workspace->users()->attach($editor, ['role' => WorkspaceRole::Editor->value]);

    $this->actingAs($editor)
        ->get(Members::getUrl(tenant: $this->workspace))
        ->assertForbidden();
});

test('the tab strip drops billing when the feature is off', function (): void {
    Feature::define(BillingFeature::class, false);

    expect(workspaceTabLabels(resolve(EditWorkspace::class)))
        ->not->toContain(__('workspaces.tabs.billing'));
});

test('each page highlights its own tab even when no page route is current', function (): void {
    Feature::define(BillingFeature::class, true);

    $tabs = [
        EditWorkspace::class => __('workspaces.tabs.general'),
        Members::class => __('workspaces.tabs.members'),
        CustomFields::class => __('workspaces.tabs.custom_fields'),
        EmailAccountsPage::class => __('workspaces.tabs.email'),
        ManageEmailTemplates::class => __('workspaces.tabs.email'),
        EmailPrivacySettingsPage::class => __('workspaces.tabs.email'),
        ImportHistory::class => __('workspaces.tabs.import_history'),
        ActivityLog::class => __('workspaces.tabs.activity'),
        Billing::class => __('workspaces.tabs.billing'),
    ];

    foreach ($tabs as $page => $label) {
        expect(activeWorkspaceTabLabels(resolve($page)))
            ->toBe([$label], "[{$page}] should highlight only its own tab");
    }
});

test('the tenant menu lists billing directly under workspace settings', function (): void {
    Feature::define(BillingFeature::class, true);

    $panel = Filament::getPanel('app');
    Filament::setCurrentPanel($panel);

    $items = collect($panel->getTenantMenuItems())
        ->filter(fn (Action $item): bool => $item->isVisible())
        ->keys()
        ->all();

    expect($items)->toBe(['profile', 'billing', 'register'])
        ->and($panel->getTenantMenuItems()['billing']->getSort())->toBeLessThan(0);
});

test('the legacy team urls redirect permanently to their workspace pages', function (?string $legacyPage, string $page): void {
    $this->get(route('filament.app.team.redirect', ['tenant' => $this->workspace->slug, 'page' => $legacyPage]))
        ->assertRedirect($page::getUrl(tenant: $this->workspace))
        ->assertStatus(301);
})->with([
    'general' => [null, EditWorkspace::class],
    'members' => ['members', Members::class],
    'activity' => ['activity', ActivityLog::class],
    'custom fields' => ['custom-fields', CustomFields::class],
]);

test('the legacy team url only redirects known pages', function (): void {
    $this->get("/app/{$this->workspace->slug}/team/billing")->assertNotFound();
});
