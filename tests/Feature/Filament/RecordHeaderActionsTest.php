<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;

mutates(ViewCompany::class, ViewPeople::class, ViewOpportunity::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('no longer exposes the AI summary or ask-about-this actions on a company', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create();

    livewire(ViewCompany::class, ['record' => $company->getKey()])
        ->assertActionDoesNotExist('generateSummary')
        ->assertActionDoesNotExist('askAboutThis')
        ->assertActionExists('edit');
});

it('no longer exposes the AI summary or ask-about-this actions on a person', function (): void {
    $person = People::factory()->recycle([$this->user, $this->team])->create();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertActionDoesNotExist('generateSummary')
        ->assertActionDoesNotExist('askAboutThis')
        ->assertActionExists('edit');
});

it('no longer exposes the AI summary or ask-about-this actions on an opportunity', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->team])->create();

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertActionDoesNotExist('generateSummary')
        ->assertActionDoesNotExist('askAboutThis')
        ->assertActionExists('edit');
});

it('hides the emails action on company, person, and opportunity views when email integration is off', function (string $page, Closure $record): void {
    Feature::deactivate(EmailIntegration::class);

    $owner = $record($this->user, $this->team);

    livewire($page, ['record' => $owner->getKey()])
        ->assertActionHidden('viewEmails');
})->with([
    'company' => [
        ViewCompany::class,
        fn (User $user, $team): Company => Company::factory()->recycle([$user, $team])->create(),
    ],
    'person' => [
        ViewPeople::class,
        fn (User $user, $team): People => People::factory()->recycle([$user, $team])->create(),
    ],
    'opportunity' => [
        ViewOpportunity::class,
        fn (User $user, $team): Opportunity => Opportunity::factory()->recycle([$user, $team])->create(),
    ],
]);

it('shows the emails action on company, person, and opportunity views when email integration is on', function (string $page, Closure $record): void {
    $owner = $record($this->user, $this->team);

    livewire($page, ['record' => $owner->getKey()])
        ->assertActionVisible('viewEmails');
})->with([
    'company' => [
        ViewCompany::class,
        fn (User $user, $team): Company => Company::factory()->recycle([$user, $team])->create(),
    ],
    'person' => [
        ViewPeople::class,
        fn (User $user, $team): People => People::factory()->recycle([$user, $team])->create(),
    ],
    'opportunity' => [
        ViewOpportunity::class,
        fn (User $user, $team): Opportunity => Opportunity::factory()->recycle([$user, $team])->create(),
    ],
]);
