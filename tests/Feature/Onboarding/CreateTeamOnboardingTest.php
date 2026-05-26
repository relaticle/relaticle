<?php

declare(strict_types=1);

use App\Actions\Jetstream\CreateTeam as CreateTeamAction;
use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Filament\Pages\CreateTeam;
use App\Filament\Resources\CompanyResource;
use App\Listeners\CreateTeamCustomFields;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Relaticle\OnboardSeed\OnboardSeedManager;

mutates(CreateTeam::class, CreateTeamAction::class, OnboardSeedManager::class, CreateTeamCustomFields::class);

it('renders the create team page with wizard for teamless users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->assertSuccessful()
        ->assertSee('Create your workspace');
});

it('renders wizard for users who already have a team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->assertSuccessful()
        ->assertSee('Create your workspace');
});

it('creates a team with onboarding fields', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'Acme Corp',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Acme Corp')->first();

    expect($team)->not->toBeNull()
        ->and($team->slug)->toBe('acme-corp')
        ->and($team->onboarding_use_case)->toBe(OnboardingUseCase::Sales);
});

it('creates a team with a custom slug', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'Acme Corp',
            'slug' => 'my-workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Acme Corp')->first();

    expect($team)->not->toBeNull()
        ->and($team->slug)->toBe('my-workspace');
});

it('validates slug format', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => 'Acme Corp',
            'slug' => 'INVALID SLUG!!',
        ])
        ->call('register')
        ->assertHasFormErrors(['slug']);
});

it('validates slug uniqueness', function (): void {
    $existingUser = User::factory()->create();
    Team::factory()->create(['slug' => 'taken-slug', 'user_id' => $existingUser->id]);

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => 'Acme Corp',
            'slug' => 'taken-slug',
        ])
        ->call('register')
        ->assertHasFormErrors(['slug']);
});

it('requires a team name', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => '',
        ])
        ->call('register')
        ->assertHasFormErrors(['name']);
});

it('marks first team as personal team', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'My First Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->ownedTeams->first();

    expect($team->personal_team)->toBeTrue();
});

it('marks subsequent teams as non-personal', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => 'Second Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $secondTeam = $user->fresh()->ownedTeams()->where('name', 'Second Team')->first();

    expect($secondTeam->personal_team)->toBeFalse();
});

it('redirects first team to companies index with notification', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'Redirect Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertNotified('Workspace created')
        ->assertRedirect(CompanyResource::getUrl('index', ['tenant' => $user->fresh()->currentTeam]));
});

it('redirects subsequent teams to companies index', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => 'Second Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect(CompanyResource::getUrl('index', ['tenant' => $user->fresh()->currentTeam]));
});

it('seeds sales demo data for sales use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'Sales Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->personalTeam();

    expect($team)->not->toBeNull();

    $companies = Company::where('team_id', $team->id)->pluck('name')->sort()->values();

    expect($companies)->toHaveCount(4)
        ->and($companies->all())->toBe(['Airbnb', 'Apple', 'Figma', 'Notion']);
});

it('seeds recruiting demo data for recruiting use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Recruiting->value,
            'onboarding_context' => ['applications'],
            'name' => 'Hiring Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->personalTeam();

    $companies = Company::where('team_id', $team->id)->pluck('name')->sort()->values();
    $people = People::where('team_id', $team->id)->pluck('name')->sort()->values();

    expect($companies)->toHaveCount(4)
        ->and($companies->all())->toBe(['Linear', 'Stripe', 'Supabase', 'Vercel'])
        ->and($people)->toHaveCount(4);
});

it('seeds marketing demo data for marketing use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Marketing->value,
            'onboarding_context' => ['content'],
            'name' => 'Marketing Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->personalTeam();

    $companies = Company::where('team_id', $team->id)->pluck('name')->sort()->values();

    expect($companies)->toHaveCount(4)
        ->and($companies->all())->toBe(['Canva', 'Clearbit', 'HubSpot', 'Mailchimp']);
});

it('seeds general demo data for other use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => 'General Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->personalTeam();

    $companies = Company::where('team_id', $team->id)->pluck('name')->sort()->values();

    expect($companies)->toHaveCount(4)
        ->and($companies->all())->toBe(['Atlas Design Studio', 'Coastal Media', 'Horizon Labs', 'Summit Group']);
});

it('seeds fundraising demo data for fundraising use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Fundraising->value,
            'onboarding_context' => ['early_stage'],
            'name' => 'Fundraising Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->personalTeam();

    $companies = Company::where('team_id', $team->id)->pluck('name')->sort()->values();

    expect($companies)->toHaveCount(4)
        ->and($companies->all())->toBe(['Andreessen Horowitz', 'Benchmark', 'Greylock Partners', 'Sequoia Capital']);
});

it('creates all custom fields for the first team', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'Custom Fields Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->personalTeam();

    $fields = CustomField::withoutGlobalScopes()
        ->where('tenant_id', $team->id)
        ->get()
        ->groupBy('entity_type');

    expect($fields->get('company'))->toHaveCount(3)
        ->and($fields->get('people'))->toHaveCount(4)
        ->and($fields->get('opportunity'))->toHaveCount(3)
        ->and($fields->get('task'))->toHaveCount(4)
        ->and($fields->get('note'))->toHaveCount(1);
});

it('seeds people linked to their correct companies for sales', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'Link Test Team',
        ])
        ->call('register');

    $team = $user->fresh()->personalTeam();
    $companies = Company::where('team_id', $team->id)->pluck('id', 'name');
    $people = People::where('team_id', $team->id)->get();

    $expectedMapping = [
        'Tim Cook' => 'Apple',
        'Brian Chesky' => 'Airbnb',
        'Dylan Field' => 'Figma',
        'Ivan Zhao' => 'Notion',
    ];

    foreach ($people as $person) {
        $expectedCompany = $expectedMapping[$person->name];
        expect($person->company_id)->toBe($companies[$expectedCompany]);
    }
});

it('seeds tasks and opportunities with board positions', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'Board Test Team',
        ])
        ->call('register');

    $team = $user->fresh()->personalTeam();

    $tasks = Task::where('team_id', $team->id)->get();
    $taskPositions = $tasks->pluck('order_column');
    expect($tasks)->toHaveCount(4)
        ->and($taskPositions->every(fn ($v) => $v !== null))->toBeTrue()
        ->and($taskPositions->unique())->toHaveCount(4);

    $opportunities = Opportunity::where('team_id', $team->id)->get();
    $opportunityPositions = $opportunities->pluck('order_column');
    expect($opportunities)->toHaveCount(4)
        ->and($opportunityPositions->every(fn ($v) => $v !== null))->toBeTrue()
        ->and($opportunityPositions->unique())->toHaveCount(4);
});

it('seeds custom field values correctly for sales', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'name' => 'Values Test Team',
        ])
        ->call('register');

    $team = $user->fresh()->personalTeam();

    $apple = Company::where('team_id', $team->id)->where('name', 'Apple')->first();
    $companyFields = CustomField::withoutGlobalScopes()
        ->where('tenant_id', $team->id)
        ->forEntity(Company::class)
        ->pluck('id', 'code');

    $appleValues = CustomFieldValue::withoutGlobalScopes()
        ->where('entity_id', $apple->id)
        ->where('entity_type', $apple->getMorphClass())
        ->get()
        ->keyBy('custom_field_id');

    expect($appleValues[$companyFields['domains']]->json_value)->toContain('https://apple.com')
        ->and($appleValues[$companyFields['icp']]->boolean_value)->toBeTrue()
        ->and($appleValues[$companyFields['linkedin']]->json_value)->toContain('https://www.linkedin.com/company/apple');
});

it('subsequent teams still require use case selection', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Second Team',
            'slug' => 'second-team',
        ])
        ->call('register')
        ->assertHasFormErrors(['onboarding_use_case' => 'required']);
});

it('subsequent teams can skip optional referral source', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Second Team',
            'slug' => 'second-team',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->ownedTeams()->where('name', 'Second Team')->first();

    expect($team)->not->toBeNull()
        ->and($team->onboarding_referral_source)->toBeNull();
});

it('provides sub-options for each use case', function (): void {
    expect(OnboardingUseCase::Sales->getSubOptions())->toHaveCount(7)
        ->and(OnboardingUseCase::CustomerSuccess->getSubOptions())->toHaveCount(5)
        ->and(OnboardingUseCase::Recruiting->getSubOptions())->toHaveCount(2)
        ->and(OnboardingUseCase::Marketing->getSubOptions())->toHaveCount(4)
        ->and(OnboardingUseCase::Fundraising->getSubOptions())->toHaveCount(3)
        ->and(OnboardingUseCase::Investing->getSubOptions())->toHaveCount(3)
        ->and(OnboardingUseCase::Other->getSubOptions())->toBe([]);
});

it('stores referral source', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'onboarding_referral_source' => OnboardingReferralSource::Google->value,
            'name' => 'Referral Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Referral Team')->first();

    expect($team->onboarding_referral_source)->toBe(OnboardingReferralSource::Google);
});

it('stores onboarding context', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led', 'enterprise'],
            'name' => 'Context Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Context Team')->first();

    expect($team->onboarding_context)->toBe(['product_led', 'enterprise']);
});

it('sends team invitations when invite emails are provided', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Invite Test Team',
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'invites' => [
                ['email' => 'alice@example.com', 'role' => 'editor'],
                ['email' => 'bob@example.com', 'role' => 'editor'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Invite Test Team')->first();

    expect($team->teamInvitations)->toHaveCount(2)
        ->and($team->teamInvitations->pluck('email')->sort()->values()->all())
        ->toBe(['alice@example.com', 'bob@example.com']);
});

it('sends invitations with correct roles', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Role Test Team',
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'invites' => [
                ['email' => 'member@example.com', 'role' => 'editor'],
                ['email' => 'admin@example.com', 'role' => 'admin'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Role Test Team')->first();
    $invitations = $team->teamInvitations->sortBy('email')->values();

    expect($invitations)->toHaveCount(2)
        ->and($invitations[0]->email)->toBe('admin@example.com')
        ->and($invitations[0]->role)->toBe('admin')
        ->and($invitations[1]->email)->toBe('member@example.com')
        ->and($invitations[1]->role)->toBe('editor');
});

it('sends only valid invitations when some emails are empty', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Partial Invite Team',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'invites' => [
                ['email' => 'alice@example.com', 'role' => 'editor'],
                ['email' => '', 'role' => 'editor'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Partial Invite Team')->first();

    expect($team->teamInvitations)->toHaveCount(1)
        ->and($team->teamInvitations->first()->email)->toBe('alice@example.com');
});

it('creates team without invitations when no emails are provided', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'No Invite Team',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'No Invite Team')->first();

    expect($team->teamInvitations)->toHaveCount(0);
});

it('maps use case to correct fixture set', function (): void {
    expect(OnboardingUseCase::Sales->getFixtureSet())->toBe('sales')
        ->and(OnboardingUseCase::CustomerSuccess->getFixtureSet())->toBe('sales')
        ->and(OnboardingUseCase::Recruiting->getFixtureSet())->toBe('recruiting')
        ->and(OnboardingUseCase::Marketing->getFixtureSet())->toBe('marketing')
        ->and(OnboardingUseCase::Fundraising->getFixtureSet())->toBe('fundraising')
        ->and(OnboardingUseCase::Investing->getFixtureSet())->toBe('fundraising')
        ->and(OnboardingUseCase::Other->getFixtureSet())->toBe('general');
});

it('seeds all entity types for each fixture set', function (OnboardingUseCase $useCase, ?array $context): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $formData = [
        'onboarding_use_case' => $useCase->value,
        'name' => "Team {$useCase->value}",
    ];

    if ($context !== null) {
        $formData['onboarding_context'] = $context;
    }

    livewire(CreateTeam::class)
        ->fillForm($formData)
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->personalTeam();

    expect(Company::where('team_id', $team->id)->count())->toBe(4)
        ->and(People::where('team_id', $team->id)->count())->toBe(4)
        ->and(Opportunity::where('team_id', $team->id)->count())->toBe(4)
        ->and(Task::where('team_id', $team->id)->count())->toBe(4)
        ->and(Note::where('team_id', $team->id)->count())->toBe(5);
})->with([
    'sales' => [OnboardingUseCase::Sales, ['product_led']],
    'recruiting' => [OnboardingUseCase::Recruiting, ['applications']],
    'marketing' => [OnboardingUseCase::Marketing, ['content']],
    'customer_success' => [OnboardingUseCase::CustomerSuccess, ['low_touch']],
    'fundraising' => [OnboardingUseCase::Fundraising, ['early_stage']],
    'investing' => [OnboardingUseCase::Investing, ['early_stage']],
    'other' => [OnboardingUseCase::Other, null],
]);

it('rejects empty onboarding context for use cases that have sub-options', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(fn () => resolve(CreateTeamAction::class)->create($user, [
        'name' => 'Tampered Team',
        'slug' => 'tampered-team',
        'onboarding_use_case' => OnboardingUseCase::Sales->value,
        'onboarding_context' => [],
    ]))->toThrow(ValidationException::class);

    expect(Team::where('slug', 'tampered-team')->exists())->toBeFalse();
});

it('rejects unknown onboarding context values for the chosen use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(fn () => resolve(CreateTeamAction::class)->create($user, [
        'name' => 'Tampered Team',
        'slug' => 'tampered-team',
        'onboarding_use_case' => OnboardingUseCase::Sales->value,
        'onboarding_context' => ['not_a_real_option'],
    ]))->toThrow(ValidationException::class);

    expect(Team::where('slug', 'tampered-team')->exists())->toBeFalse();
});

it('allows empty onboarding context for use cases without sub-options', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $team = resolve(CreateTeamAction::class)->create($user, [
        'name' => 'Other Use Case',
        'slug' => 'other-use-case',
        'onboarding_use_case' => OnboardingUseCase::Other->value,
    ]);

    expect($team->slug)->toBe('other-use-case')
        ->and($team->onboarding_use_case)->toBe(OnboardingUseCase::Other);
});
