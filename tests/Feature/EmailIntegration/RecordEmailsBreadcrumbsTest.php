<?php

declare(strict_types=1);

use App\Filament\Resources\CompanyResource;
use App\Filament\Resources\CompanyResource\Pages\CompanyEmailsPage;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Pages\BaseRecordEmailsPage;

mutates(BaseRecordEmailsPage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->company = Company::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->user->id,
    ]);

    $this->actingAs($this->user);
    Filament::setTenant($this->team);
});

it('walks from the resource index to the record to this page', function (): void {
    $page = livewire(CompanyEmailsPage::class, ['record' => $this->company->getKey()]);

    expect($page->instance()->getBreadcrumbs())
        ->toHaveKey(CompanyResource::getUrl('index'))
        ->and(array_values($page->instance()->getBreadcrumbs()))
        ->toContain($this->company->name)
        ->and(array_values($page->instance()->getBreadcrumbs()))
        ->toContain('Emails');
});

it('uses a human page title', function (): void {
    $page = livewire(CompanyEmailsPage::class, ['record' => $this->company->getKey()]);

    expect($page->instance()->getTitle())->toBe('Emails');
});

it('renders the crumbs, which the panel would otherwise drop', function (): void {
    // The app panel disables breadcrumbs globally, so the page view renders its own —
    // asserting the method alone would pass with nothing on screen.
    livewire(CompanyEmailsPage::class, ['record' => $this->company->getKey()])
        ->assertSeeHtml('fi-breadcrumbs')
        ->assertSee($this->company->name)
        ->assertSee('Emails');
});
