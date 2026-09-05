<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Actions\ViewRecordEmailsAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(ViewCompany::class, ViewPeople::class, ViewOpportunity::class, ViewRecordEmailsAction::class, EmailVisibilityService::class);

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

/**
 * @return array<string, array{0: class-string, 1: Closure(User, Team): (Company|Opportunity|People)}>
 */
function recordEmailsHeaderPages(): array
{
    return [
        'company' => [
            ViewCompany::class,
            fn (User $user, Team $team): Company => Company::factory()->recycle([$user, $team])->create(['email_count' => 99]),
        ],
        'person' => [
            ViewPeople::class,
            fn (User $user, Team $team): People => People::factory()->recycle([$user, $team])->create(['email_count' => 99]),
        ],
        'opportunity' => [
            ViewOpportunity::class,
            fn (User $user, Team $team): Opportunity => Opportunity::factory()->recycle([$user, $team])->create(['email_count' => 99]),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function attachRecordEmail(User $user, Team $team, Company|Opportunity|People $record, array $overrides = []): Email
{
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $team->id,
        'user_id' => $user->id,
    ]));

    $email = Email::factory()->create(array_merge([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'connected_account_id' => $account->getKey(),
    ], $overrides));

    $record->emails()->attach($email->getKey());

    return $email;
}

function emailsHeaderBadge(string $page, Company|Opportunity|People $record): ?string
{
    return livewire($page, ['record' => $record->getKey()])
        ->instance()
        ->getAction('viewEmails', isMounting: false)
        ?->getBadge();
}

it('badges the emails header action with the visible count for the record', function (string $page, Closure $record): void {
    $owner = $record($this->user, $this->team);
    attachRecordEmail($this->user, $this->team, $owner);
    attachRecordEmail($this->user, $this->team, $owner);

    expect(emailsHeaderBadge($page, $owner))->toBe('2');
})->with(recordEmailsHeaderPages());

it('colors the emails header badge so it stays readable on the gray action', function (): void {
    $person = People::factory()->recycle([$this->user, $this->team])->create();
    attachRecordEmail($this->user, $this->team, $person);

    $action = livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->instance()
        ->getAction('viewEmails', isMounting: false);

    expect($action?->getBadgeColor($action->getBadge()))->toBe('primary');
});

it('hides the emails header badge when the record has no visible mail', function (string $page, Closure $record): void {
    $owner = $record($this->user, $this->team);

    expect(emailsHeaderBadge($page, $owner))->toBeNull();
})->with(recordEmailsHeaderPages());

it('caps the emails header badge at 99+', function (): void {
    $person = People::factory()->recycle([$this->user, $this->team])->create();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $emails = Email::factory()->count(100)->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
    ]);

    $person->emails()->attach($emails->modelKeys());

    expect(emailsHeaderBadge(ViewPeople::class, $person))->toBe('99+');
});

it('does not badge private teammate mail or emails linked to another record', function (string $page, Closure $record): void {
    $owner = $record($this->user, $this->team);
    $other = $record($this->user, $this->team);

    attachRecordEmail($this->user, $this->team, $owner);

    $coworker = User::factory()->create(['current_team_id' => $this->team->id]);
    $this->team->users()->attach($coworker, ['role' => 'editor']);

    attachRecordEmail($coworker, $this->team, $owner, [
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'is_internal' => false,
    ]);
    attachRecordEmail($this->user, $this->team, $other);

    expect(emailsHeaderBadge($page, $owner))->toBe('1');
})->with(recordEmailsHeaderPages());
