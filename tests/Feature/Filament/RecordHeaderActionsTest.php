<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Filament\Resources\CompanyResource\Pages\ViewCompany;
use App\Filament\Resources\CompanyResource\RelationManagers\EmailsRelationManager as CompanyEmailsRelationManager;
use App\Filament\Resources\CompanyResource\RelationManagers\MeetingsRelationManager as CompanyMeetingsRelationManager;
use App\Filament\Resources\OpportunityResource\Pages\ViewOpportunity;
use App\Filament\Resources\OpportunityResource\RelationManagers\EmailsRelationManager as OpportunityEmailsRelationManager;
use App\Filament\Resources\OpportunityResource\RelationManagers\MeetingsRelationManager as OpportunityMeetingsRelationManager;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager as PeopleEmailsRelationManager;
use App\Filament\Resources\PeopleResource\RelationManagers\MeetingsRelationManager as PeopleMeetingsRelationManager;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Data\VisibleCommunicationIntelligence;
use Relaticle\EmailIntegration\Enums\ConnectionStrength;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Infolists\CommunicationIntelligenceInfolist;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseEmailsRelationManager;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(ViewCompany::class, ViewPeople::class, ViewOpportunity::class, BaseEmailsRelationManager::class, EmailVisibilityService::class, CommunicationIntelligenceInfolist::class, VisibleCommunicationIntelligence::class, ConnectionStrength::class);

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
        ->assertActionExists(TestAction::make('edit')->schemaComponent('recordActions', schema: 'infolist'));
});

it('no longer exposes the AI summary or ask-about-this actions on a person', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertActionDoesNotExist('generateSummary')
        ->assertActionDoesNotExist('askAboutThis')
        ->assertActionExists(TestAction::make('edit')->schemaComponent('recordActions', schema: 'infolist'));
});

it('no longer exposes the AI summary or ask-about-this actions on an opportunity', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertActionDoesNotExist('generateSummary')
        ->assertActionDoesNotExist('askAboutThis')
        ->assertActionExists(TestAction::make('edit')->schemaComponent('recordActions', schema: 'infolist'));
});

/**
 * @return array<string, array{0: class-string, 1: class-string<BaseEmailsRelationManager>, 2: class-string, 3: Closure(User, Workspace): (Company|Opportunity|People)}>
 */
function recordEmailsTabPages(): array
{
    return [
        'company' => [
            ViewCompany::class,
            CompanyEmailsRelationManager::class,
            CompanyMeetingsRelationManager::class,
            fn (User $user, Workspace $team): Company => Company::factory()->recycle([$user, $team])->create(),
        ],
        'person' => [
            ViewPeople::class,
            PeopleEmailsRelationManager::class,
            PeopleMeetingsRelationManager::class,
            fn (User $user, Workspace $team): People => People::factory()->recycle([$user, $team])->create(),
        ],
        'opportunity' => [
            ViewOpportunity::class,
            OpportunityEmailsRelationManager::class,
            OpportunityMeetingsRelationManager::class,
            fn (User $user, Workspace $team): Opportunity => Opportunity::factory()->recycle([$user, $team])->create(),
        ],
    ];
}

/**
 * @return array<int, class-string>
 */
function viewPageRelationManagers(string $page, Company|Opportunity|People $record): array
{
    return livewire($page, ['record' => $record->getKey()])
        ->instance()
        ->getRelationManagers();
}

it('hides the emails and meetings tabs on company, person, and opportunity views when email integration is off', function (string $page, string $emailsRelationManager, string $meetingsRelationManager, Closure $record): void {
    Feature::deactivate(EmailIntegration::class);

    $managers = viewPageRelationManagers($page, $record($this->user, $this->workspace));

    expect($managers)->not->toContain($emailsRelationManager)
        ->and($managers)->not->toContain($meetingsRelationManager);
})->with(recordEmailsTabPages());

it('shows the emails and meetings tabs on company, person, and opportunity views when email integration is on', function (string $page, string $emailsRelationManager, string $meetingsRelationManager, Closure $record): void {
    $managers = viewPageRelationManagers($page, $record($this->user, $this->workspace));

    expect($managers)->toContain($emailsRelationManager)
        ->and($managers)->toContain($meetingsRelationManager);
})->with(recordEmailsTabPages());

/**
 * @return array<string, array{0: class-string, 1: class-string<BaseEmailsRelationManager>, 2: Closure(User, Workspace): (Company|Opportunity|People)}>
 */
function recordEmailsBadgePages(): array
{
    return [
        'company' => [
            ViewCompany::class,
            CompanyEmailsRelationManager::class,
            fn (User $user, Workspace $team): Company => Company::factory()->recycle([$user, $team])->create(['email_count' => 99]),
        ],
        'person' => [
            ViewPeople::class,
            PeopleEmailsRelationManager::class,
            fn (User $user, Workspace $team): People => People::factory()->recycle([$user, $team])->create(['email_count' => 99]),
        ],
        'opportunity' => [
            ViewOpportunity::class,
            OpportunityEmailsRelationManager::class,
            fn (User $user, Workspace $team): Opportunity => Opportunity::factory()->recycle([$user, $team])->create(['email_count' => 99]),
        ],
    ];
}

/**
 * @param  array<string, mixed>  $overrides
 */
function attachRecordEmail(User $user, Workspace $team, Company|Opportunity|People $record, array $overrides = []): Email
{
    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $user->id,
    ]));

    $email = Email::factory()->create(array_merge([
        'workspace_id' => $team->id,
        'user_id' => $user->id,
        'connected_account_id' => $account->getKey(),
    ], $overrides));

    $record->emails()->attach($email->getKey());

    return $email;
}

it('badges the emails tab with the visible count for the record', function (string $page, string $emailsRelationManager, Closure $record): void {
    $owner = $record($this->user, $this->workspace);
    attachRecordEmail($this->user, $this->workspace, $owner);
    attachRecordEmail($this->user, $this->workspace, $owner);

    expect($emailsRelationManager::getBadge($owner, $page))->toBe('2');
})->with(recordEmailsBadgePages());

it('colors the emails tab badge gray like its sibling tabs', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    expect(PeopleEmailsRelationManager::getBadgeColor($person, ViewPeople::class))->toBe('gray');
});

it('hides the emails tab badge when the record has no visible mail', function (string $page, string $emailsRelationManager, Closure $record): void {
    $owner = $record($this->user, $this->workspace);

    expect($emailsRelationManager::getBadge($owner, $page))->toBeNull();
})->with(recordEmailsBadgePages());

it('caps the emails tab badge at 99+', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $emails = Email::factory()->count(100)->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $account->getKey(),
    ]);

    $person->emails()->attach($emails->modelKeys());

    expect(PeopleEmailsRelationManager::getBadge($person, ViewPeople::class))->toBe('99+');
});

it('does not badge private teammate mail or emails linked to another record', function (string $page, string $emailsRelationManager, Closure $record): void {
    $owner = $record($this->user, $this->workspace);
    $other = $record($this->user, $this->workspace);

    attachRecordEmail($this->user, $this->workspace, $owner);

    $coworker = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($coworker, ['role' => 'member']);

    attachRecordEmail($coworker, $this->workspace, $owner, [
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'is_internal' => false,
    ]);
    attachRecordEmail($this->user, $this->workspace, $other);

    expect($emailsRelationManager::getBadge($owner, $page))->toBe('1');
})->with(recordEmailsBadgePages());

it('renders scoped communication intelligence once on the person view', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create([
        'email_count' => 99,
        'inbound_email_count' => 50,
        'outbound_email_count' => 49,
    ]);

    attachRecordEmail($this->user, $this->workspace, $person);

    $coworker = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($coworker, ['role' => 'member']);

    attachRecordEmail($coworker, $this->workspace, $person, [
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'is_internal' => false,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertOk()
        ->assertSee(__('filament/communication-intelligence.heading'))
        ->assertSeeHtml('isCollapsed: true')
        ->assertSee(__('filament/communication-intelligence.fields.last_interaction.label'))
        ->assertSee(__('filament/communication-intelligence.fields.last_email.label'))
        ->assertSee(__('filament/communication-intelligence.fields.next_calendar.label'))
        ->assertSee(__('filament/communication-intelligence.fields.connection_strength.label'))
        ->assertSee(__('filament/communication-intelligence.fields.strongest_connection.label'))
        ->assertSee(__('filament/communication-intelligence.connection_strength.weak'))
        ->assertDontSee('Total Emails')
        ->assertSchemaStateSet([
            'visible_connection_strength' => ConnectionStrength::Weak,
            'visible_strongest_connection' => $this->user->name,
        ]);

    $emailCountAggregates = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'as email_count'))
        ->count();

    DB::disableQueryLog();

    expect($emailCountAggregates)->toBe(1);
});
