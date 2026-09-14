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
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Data\VisibleCommunicationIntelligence;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Actions\ViewRecordEmailsAction;
use Relaticle\EmailIntegration\Filament\Infolists\CommunicationIntelligenceInfolist;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(ViewCompany::class, ViewPeople::class, ViewOpportunity::class, ViewRecordEmailsAction::class, EmailVisibilityService::class, CommunicationIntelligenceInfolist::class, VisibleCommunicationIntelligence::class);

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
        ->assertActionExists('edit');
});

it('no longer exposes the AI summary or ask-about-this actions on a person', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertActionDoesNotExist('generateSummary')
        ->assertActionDoesNotExist('askAboutThis')
        ->assertActionExists('edit');
});

it('no longer exposes the AI summary or ask-about-this actions on an opportunity', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create();

    livewire(ViewOpportunity::class, ['record' => $opportunity->getKey()])
        ->assertActionDoesNotExist('generateSummary')
        ->assertActionDoesNotExist('askAboutThis')
        ->assertActionExists('edit');
});

it('hides the emails action on company, person, and opportunity views when email integration is off', function (string $page, Closure $record): void {
    Feature::deactivate(EmailIntegration::class);

    $owner = $record($this->user, $this->workspace);

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
    $owner = $record($this->user, $this->workspace);

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
 * @return array<string, array{0: class-string, 1: Closure(User, Workspace): (Company|Opportunity|People)}>
 */
function recordEmailsHeaderPages(): array
{
    return [
        'company' => [
            ViewCompany::class,
            fn (User $user, Workspace $team): Company => Company::factory()->recycle([$user, $team])->create(['email_count' => 99]),
        ],
        'person' => [
            ViewPeople::class,
            fn (User $user, Workspace $team): People => People::factory()->recycle([$user, $team])->create(['email_count' => 99]),
        ],
        'opportunity' => [
            ViewOpportunity::class,
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

function emailsHeaderBadge(string $page, Company|Opportunity|People $record): ?string
{
    return livewire($page, ['record' => $record->getKey()])
        ->instance()
        ->getAction('viewEmails', isMounting: false)
        ?->getBadge();
}

it('badges the emails header action with the visible count for the record', function (string $page, Closure $record): void {
    $owner = $record($this->user, $this->workspace);
    attachRecordEmail($this->user, $this->workspace, $owner);
    attachRecordEmail($this->user, $this->workspace, $owner);

    expect(emailsHeaderBadge($page, $owner))->toBe('2');
})->with(recordEmailsHeaderPages());

it('colors the emails header badge so it stays readable on the gray action', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    attachRecordEmail($this->user, $this->workspace, $person);

    $action = livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->instance()
        ->getAction('viewEmails', isMounting: false);

    expect($action?->getBadgeColor($action->getBadge()))->toBe('primary');
});

it('hides the emails header badge when the record has no visible mail', function (string $page, Closure $record): void {
    $owner = $record($this->user, $this->workspace);

    expect(emailsHeaderBadge($page, $owner))->toBeNull();
})->with(recordEmailsHeaderPages());

it('caps the emails header badge at 99+', function (): void {
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

    expect(emailsHeaderBadge(ViewPeople::class, $person))->toBe('99+');
});

it('does not badge private teammate mail or emails linked to another record', function (string $page, Closure $record): void {
    $owner = $record($this->user, $this->workspace);
    $other = $record($this->user, $this->workspace);

    attachRecordEmail($this->user, $this->workspace, $owner);

    $coworker = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($coworker, ['role' => 'editor']);

    attachRecordEmail($coworker, $this->workspace, $owner, [
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'is_internal' => false,
    ]);
    attachRecordEmail($this->user, $this->workspace, $other);

    expect(emailsHeaderBadge($page, $owner))->toBe('1');
})->with(recordEmailsHeaderPages());

it('renders scoped communication intelligence once on the person view', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create([
        'email_count' => 99,
        'inbound_email_count' => 50,
        'outbound_email_count' => 49,
    ]);

    attachRecordEmail($this->user, $this->workspace, $person);

    $coworker = User::factory()->create(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->users()->attach($coworker, ['role' => 'editor']);

    attachRecordEmail($coworker, $this->workspace, $person, [
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'is_internal' => false,
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    livewire(ViewPeople::class, ['record' => $person->getKey()])
        ->assertOk()
        ->assertSee(__('filament/communication-intelligence.heading'))
        ->assertSee(__('filament/communication-intelligence.groups.connection'))
        ->assertSee(__('filament/communication-intelligence.groups.email'))
        ->assertSee(__('filament/communication-intelligence.groups.calendar'))
        ->assertSeeHtml('isCollapsed: true')
        ->assertSee(__('filament/communication-intelligence.fields.last_interaction.label'))
        ->assertSee(__('filament/communication-intelligence.fields.last_email.label'))
        ->assertSee(__('filament/communication-intelligence.fields.connection_strength.label'))
        ->assertSee(__('filament/communication-intelligence.fields.strongest_connection.label'))
        ->assertDontSee('Total Emails')
        ->assertSchemaStateSet([
            'visible_connection_strength' => __('filament/communication-intelligence.connection_strength.weak'),
            'visible_strongest_connection' => $this->user->name,
        ]);

    $emailCountAggregates = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains((string) $query['query'], 'as email_count'))
        ->count();

    DB::disableQueryLog();

    expect($emailCountAggregates)->toBe(1);
});
