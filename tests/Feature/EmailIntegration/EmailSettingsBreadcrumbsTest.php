<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailSettingsHeader;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailSignaturesPage;
use Relaticle\EmailIntegration\Filament\Pages\UserEmailPrivacyPage;
use Relaticle\EmailIntegration\Filament\Resources\EmailTemplateResource\Pages\ManageEmailTemplates;

mutates(HasEmailSettingsHeader::class, EmailAccountsPage::class, ManageEmailTemplates::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

it('renders a breadcrumb trail back to email settings on signature, privacy, and access-request pages', function (string $page, string $crumb): void {
    livewire($page)
        ->assertSeeHtml('fi-breadcrumbs')
        ->assertSeeHtml('href="'.e(EmailAccountsPage::getUrl()).'"')
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
