<?php

declare(strict_types=1);

use App\Filament\Concerns\RendersRecordSplitView;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;

mutates(RendersRecordSplitView::class, ViewCompany::class, ViewPeople::class, ViewOpportunity::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

it('no longer exposes the AI summary or ask-about-this actions on a company', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionDoesNotExist('generateSummary')
        ->assertActionDoesNotExist('askAboutThis')
        ->assertActionDoesNotExist(TestAction::make('edit')->schemaComponent('companyDetails'))
        ->assertActionExists(TestAction::make('copyPageUrl')->schemaComponent('companyDetails'))
        ->assertActionExists(TestAction::make('delete')->schemaComponent('companyDetails'));
});

it('no longer exposes the AI summary or ask-about-this actions on a person', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertActionDoesNotExist('generateSummary')
        ->assertActionDoesNotExist('askAboutThis')
        ->assertActionDoesNotExist(TestAction::make('edit')->schemaComponent('personDetails'))
        ->assertActionExists(TestAction::make('copyPageUrl')->schemaComponent('personDetails'))
        ->assertActionExists(TestAction::make('delete')->schemaComponent('personDetails'));
});

it('no longer exposes the AI summary or ask-about-this actions on an opportunity', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertActionDoesNotExist('generateSummary')
        ->assertActionDoesNotExist('askAboutThis')
        ->assertActionDoesNotExist(TestAction::make('edit')->schemaComponent('opportunityDetails'))
        ->assertActionExists(TestAction::make('copyPageUrl')->schemaComponent('opportunityDetails'))
        ->assertActionExists(TestAction::make('delete')->schemaComponent('opportunityDetails'));
});
