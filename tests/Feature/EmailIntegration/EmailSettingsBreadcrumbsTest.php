<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Concerns\HasClusterBreadcrumbs;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailSignaturesPage;
use Relaticle\EmailIntegration\Filament\Pages\UserEmailPrivacyPage;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;

mutates(HasClusterBreadcrumbs::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);
});

/**
 * The app panel disables breadcrumbs globally, so cluster pages render their own header;
 * every page in the cluster must still show the trail back to Email settings.
 */
it('renders the cluster breadcrumb trail on every email settings page', function (string $page, string $crumb): void {
    livewire($page)
        ->assertSee(__('filament/clusters/email-settings.breadcrumb'))
        ->assertSee($crumb);
})->with([
    [EmailSignaturesPage::class, 'Signatures'],
    [ManageEmailTemplates::class, 'Templates'],
    [UserEmailPrivacyPage::class, 'My Email Privacy'],
    [EmailAccessRequestsPage::class, 'Access Requests'],
]);
