<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Concerns\HasClusterBreadcrumbs;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailSignaturesPage;
use Relaticle\EmailIntegration\Filament\Pages\UserEmailPrivacyPage;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;

mutates(HasClusterBreadcrumbs::class, EmailAccountsPage::class, ManageEmailTemplates::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

/**
 * The app panel disables breadcrumbs globally, so remaining cluster pages render their
 * own header trail. Accounts and Templates sit under top tabs and do not repeat it.
 */
it('renders the cluster breadcrumb trail on signature, privacy, and access-request pages', function (string $page, string $crumb): void {
    livewire($page)
        ->assertSee(__('filament/clusters/email-settings.breadcrumb'))
        ->assertSee($crumb);
})->with([
    [EmailSignaturesPage::class, 'Signatures'],
    [UserEmailPrivacyPage::class, 'My Email Privacy'],
    [EmailAccessRequestsPage::class, 'Access Requests'],
]);

it('does not render breadcrumbs on the accounts and templates pages', function (string $page): void {
    livewire($page)->assertDontSeeHtml('fi-breadcrumbs');
})->with([
    [EmailAccountsPage::class],
    [ManageEmailTemplates::class],
]);
