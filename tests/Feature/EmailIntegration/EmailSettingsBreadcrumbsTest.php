<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailSettingsHeader;
use Relaticle\EmailIntegration\Filament\Concerns\HasEmailSettingsTrail;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountSettingsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailPrivacySettingsPage;
use Relaticle\EmailIntegration\Filament\Pages\EmailSignaturesPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Symfony\Component\DomCrawler\Crawler;

mutates(HasEmailSettingsTrail::class, HasEmailSettingsHeader::class, EmailPrivacySettingsPage::class, EmailAccountSettingsPage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

/** @return array{trail: ?string, href: ?string, title: string, inPageBreadcrumbs: int} */
function emailSettingsHeading(string $html): array
{
    $page = new Crawler($html);
    $heading = $page->filter('[data-page-heading]');
    $trail = $heading->filter('.fi-record-breadcrumb-link');

    return [
        'trail' => $trail->count() > 0 ? trim($trail->text()) : null,
        'href' => $trail->count() > 0 ? $trail->attr('href') : null,
        'title' => trim($heading->filter('.fi-topbar-page-title')->text()),
        'inPageBreadcrumbs' => $page->filter('.fi-breadcrumbs')->count(),
    ];
}

it('titles the :dataset page under a topbar link back to email settings', function (string $page, array $parameters, string $title): void {
    expect(emailSettingsHeading(livewire($page, $parameters)->html()))->toBe([
        'trail' => __('workspaces.tabs.email'),
        'href' => EmailAccountsPage::getUrl(),
        'title' => __($title),
        'inPageBreadcrumbs' => 0,
    ]);
})->with([
    'signatures' => [EmailSignaturesPage::class, [], 'filament/pages/email-signatures.title'],
    'access requests' => [EmailAccessRequestsPage::class, [], 'filament/pages/email-access-requests.navigation_label'],
    'email visibility' => [EmailPrivacySettingsPage::class, [], 'filament/pages/email-privacy-settings.tabs.visibility'],
    'record creation' => [EmailPrivacySettingsPage::class, ['tab' => 'record_creation'], 'filament/pages/email-privacy-settings.tabs.record_creation'],
]);

it('retitles the privacy page when another tab is picked', function (): void {
    $page = livewire(EmailPrivacySettingsPage::class)->call('setTab', 'record_creation');

    expect(emailSettingsHeading($page->html())['title'])
        ->toBe(__('filament/pages/email-privacy-settings.tabs.record_creation'));
});

it('titles a connected account under the same link back to email settings', function (): void {
    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->user->currentWorkspace->id,
        'user_id' => $this->user->id,
    ]));

    $heading = emailSettingsHeading(livewire(EmailAccountSettingsPage::class, ['account' => $account->id])->html());

    expect($heading['trail'])->toBe(__('workspaces.tabs.email'))
        ->and($heading['href'])->toBe(EmailAccountsPage::getUrl())
        ->and($heading['title'])->toBe($account->email_address);
});

it('shows no trail on the accounts page, the root of email settings', function (): void {
    expect(emailSettingsHeading(livewire(EmailAccountsPage::class)->html()))->toMatchArray([
        'trail' => null,
        'title' => __('workspaces.tabs.email'),
    ]);
});
