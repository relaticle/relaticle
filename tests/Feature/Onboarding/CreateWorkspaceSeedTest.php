<?php

declare(strict_types=1);

use App\Actions\Jetstream\CreateWorkspace as CreateWorkspaceAction;
use App\Enums\OnboardingUseCase;
use App\Features\OnboardSeed;
use App\Filament\Pages\CreateWorkspace;
use App\Listeners\CreateWorkspaceCustomFields;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Pennant\Feature;
use Relaticle\OnboardSeed\OnboardSeedManager;

mutates(CreateWorkspace::class, CreateWorkspaceAction::class, OnboardSeedManager::class, CreateWorkspaceCustomFields::class);

// This file is the coverage for demo seeding itself, so it opts back into the
// feature that TestCase switches off for the rest of the suite.
beforeEach(function (): void {
    Feature::define(OnboardSeed::class, true);
});

it('seeds sales demo data for sales use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['outbound'],
            'name' => 'Sales Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->personalWorkspace();

    expect($workspace)->not->toBeNull();

    $companies = Company::where('workspace_id', $workspace->id)->pluck('name')->sort()->values();

    expect($companies)->toHaveCount(4)
        ->and($companies->all())->toBe(['Airbnb', 'Apple', 'Figma', 'Notion']);
});

it('seeds recruiting demo data for recruiting use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Recruiting->value,
            'onboarding_context' => ['applications'],
            'name' => 'Hiring Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->personalWorkspace();

    $companies = Company::where('workspace_id', $workspace->id)->pluck('name')->sort()->values();
    $people = People::where('workspace_id', $workspace->id)->pluck('name')->sort()->values();

    expect($companies)->toHaveCount(4)
        ->and($companies->all())->toBe(['Linear', 'Stripe', 'Supabase', 'Vercel'])
        ->and($people)->toHaveCount(4);
});

it('seeds marketing demo data for marketing use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Marketing->value,
            'onboarding_context' => ['content'],
            'name' => 'Marketing Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->personalWorkspace();

    $companies = Company::where('workspace_id', $workspace->id)->pluck('name')->sort()->values();

    expect($companies)->toHaveCount(4)
        ->and($companies->all())->toBe(['Canva', 'Clearbit', 'HubSpot', 'Mailchimp']);
});

it('seeds general demo data for other use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'name' => 'General Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->personalWorkspace();

    $companies = Company::where('workspace_id', $workspace->id)->pluck('name')->sort()->values();

    expect($companies)->toHaveCount(4)
        ->and($companies->all())->toBe(['Atlas Design Studio', 'Coastal Media', 'Horizon Labs', 'Summit Group']);
});

it('seeds fundraising demo data for fundraising use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Fundraising->value,
            'onboarding_context' => ['early_stage'],
            'name' => 'Fundraising Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->personalWorkspace();

    $companies = Company::where('workspace_id', $workspace->id)->pluck('name')->sort()->values();

    expect($companies)->toHaveCount(4)
        ->and($companies->all())->toBe(['Andreessen Horowitz', 'Benchmark', 'Greylock Partners', 'Sequoia Capital']);
});

it('creates all custom fields for the first workspace', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['outbound'],
            'name' => 'Custom Fields Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->personalWorkspace();

    $fields = CustomField::withoutGlobalScopes()
        ->where('tenant_id', $workspace->id)
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

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['outbound'],
            'name' => 'Link Test Workspace',
        ])
        ->call('register');

    $workspace = $user->fresh()->personalWorkspace();
    $companies = Company::where('workspace_id', $workspace->id)->pluck('id', 'name');
    $people = People::where('workspace_id', $workspace->id)->get();

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

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['outbound'],
            'name' => 'Board Test Workspace',
        ])
        ->call('register');

    $workspace = $user->fresh()->personalWorkspace();

    $tasks = Task::where('workspace_id', $workspace->id)->get();
    $taskPositions = $tasks->pluck('order_column');
    expect($tasks)->toHaveCount(4)
        ->and($taskPositions->every(fn ($v) => $v !== null))->toBeTrue()
        ->and($taskPositions->unique())->toHaveCount(4);

    $opportunities = Opportunity::where('workspace_id', $workspace->id)->get();
    $opportunityPositions = $opportunities->pluck('order_column');
    expect($opportunities)->toHaveCount(4)
        ->and($opportunityPositions->every(fn ($v) => $v !== null))->toBeTrue()
        ->and($opportunityPositions->unique())->toHaveCount(4);
});

it('seeds custom field values correctly for sales', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['outbound'],
            'name' => 'Values Test Workspace',
        ])
        ->call('register');

    $workspace = $user->fresh()->personalWorkspace();

    $apple = Company::where('workspace_id', $workspace->id)->where('name', 'Apple')->first();
    $companyFields = CustomField::withoutGlobalScopes()
        ->where('tenant_id', $workspace->id)
        ->forEntity(Company::class)
        ->pluck('id', 'code');

    $appleValues = CustomFieldValue::withoutGlobalScopes()
        ->where('entity_id', $apple->id)
        ->where('entity_type', $apple->getMorphClass())
        ->get()
        ->keyBy('custom_field_id');

    expect($appleValues[$companyFields['domains']]->json_value)->toContain('www.apple.com')
        ->and($appleValues[$companyFields['icp']]->boolean_value)->toBeTrue()
        ->and($appleValues[$companyFields['linkedin']]->json_value)->toContain('www.linkedin.com/company/apple');
});

it('subsequent workspaces still require use case selection', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Second Workspace',
            'slug' => 'second-workspace',
        ])
        ->call('register')
        ->assertHasFormErrors(['onboarding_use_case' => 'required']);
});

it('provides one axis of sub-options for each use case', function (): void {
    expect(OnboardingUseCase::Sales->getSubOptions())->toBe([
        'outbound' => 'Outbound',
        'inbound' => 'Inbound',
        'product_led' => 'Product-led',
        'partner_led' => 'Partner-led',
    ])
        ->and(OnboardingUseCase::CustomerSuccess->getSubOptions())->toHaveKeys(['high_touch', 'low_touch'])
        ->and(OnboardingUseCase::Recruiting->getSubOptions())->toHaveKeys(['applications', 'sourcing'])
        ->and(OnboardingUseCase::Marketing->getSubOptions())->toHaveKeys(['content', 'demand_gen', 'events', 'partnerships'])
        ->and(OnboardingUseCase::Fundraising->getSubOptions())->toHaveKeys(['early_stage', 'growth_stage', 'late_stage'])
        ->and(OnboardingUseCase::Investing->getSubOptions())->toHaveKeys(['early_stage', 'growth_stage', 'late_stage'])
        ->and(OnboardingUseCase::Other->getSubOptions())->toBe([]);
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

it('seeds all entity types for each fixture set', function (OnboardingUseCase $useCase): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $formData = [
        'onboarding_use_case' => $useCase->value,
        'name' => "Workspace {$useCase->value}",
    ];

    $context = array_key_first($useCase->getSubOptions());

    if ($context !== null) {
        $formData['onboarding_context'] = [$context];
    }

    livewire(CreateWorkspace::class)
        ->fillForm($formData)
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->personalWorkspace();

    expect(Company::where('workspace_id', $workspace->id)->count())->toBe(4)
        ->and(People::where('workspace_id', $workspace->id)->count())->toBe(4)
        ->and(Opportunity::where('workspace_id', $workspace->id)->count())->toBe(4)
        ->and(Task::where('workspace_id', $workspace->id)->count())->toBe(4)
        ->and(Note::where('workspace_id', $workspace->id)->count())->toBe(5);
})->with([
    'sales' => OnboardingUseCase::Sales,
    'recruiting' => OnboardingUseCase::Recruiting,
    'marketing' => OnboardingUseCase::Marketing,
    'customer_success' => OnboardingUseCase::CustomerSuccess,
    'fundraising' => OnboardingUseCase::Fundraising,
    'investing' => OnboardingUseCase::Investing,
    'other' => OnboardingUseCase::Other,
]);

it('generates a fallback handle for names that transliterate to nothing', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $state = livewire(CreateWorkspace::class)
        ->fillForm(['name' => '株式会社テスト'])
        ->get('data');

    expect($state['slug'] ?? null)->toBeString()
        ->not->toBe('')
        ->toMatch(Workspace::SLUG_REGEX);
});

it('assigns seeded demo tasks to the workspace owner so the dashboard is not empty', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['outbound'],
            'name' => 'Assigned Tasks Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->personalWorkspace();

    $tasks = Task::where('workspace_id', $workspace->id)->get();

    expect($tasks)->not->toBeEmpty();

    $tasks->each(function (Task $task) use ($user): void {
        expect($task->assignees()->whereKey($user->getKey())->exists())->toBeTrue();
    });
});
