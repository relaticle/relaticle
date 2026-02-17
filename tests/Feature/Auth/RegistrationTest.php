<?php

declare(strict_types=1);

use App\Filament\Pages\Auth\Register;
use App\Listeners\CreateTeamCustomFields;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Filament\Auth\Events\Registered;
use Laravel\Jetstream\Events\TeamCreated;
use Relaticle\OnboardSeed\OnboardSeedManager;

mutates(OnboardSeedManager::class, CreateTeamCustomFields::class);

beforeEach(function (): void {
    Event::fake()->except([
        'eloquent.creating: App\\Models\\Team',
        TeamCreated::class,
        Registered::class,
    ]);
});

test('registering a new user creates a personal team with demo data', function (): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Jane Doe',
            'email' => 'jane-test@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $user = User::where('email', 'jane-test@gmail.com')->first();
    expect($user)->not->toBeNull();

    $team = $user->personalTeam();
    expect($team)->not->toBeNull()
        ->and($team->personal_team)->toBeTrue();

    expect(Company::where('team_id', $team->id)->count())->toBe(4)
        ->and(People::where('team_id', $team->id)->count())->toBe(4)
        ->and(Opportunity::where('team_id', $team->id)->count())->toBe(4)
        ->and(Task::where('team_id', $team->id)->count())->toBe(4)
        ->and(Note::where('team_id', $team->id)->count())->toBe(5);
});

test('registration creates all custom fields for the personal team', function (): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'John Smith',
            'email' => 'john-test@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = User::where('email', 'john-test@gmail.com')->first()->personalTeam();

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

test('seeded people are linked to their correct companies', function (): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Link Test',
            'email' => 'linktest@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register');

    $team = User::where('email', 'linktest@gmail.com')->first()->personalTeam();
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

test('seeded tasks and opportunities have board positions', function (): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Board Test',
            'email' => 'boardtest@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register');

    $team = User::where('email', 'boardtest@gmail.com')->first()->personalTeam();

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

test('seeded custom field values are stored correctly', function (): void {
    livewire(Register::class)
        ->fillForm([
            'name' => 'Val Test',
            'email' => 'valtest@gmail.com',
            'password' => 'Password123!',
            'passwordConfirmation' => 'Password123!',
        ])
        ->call('register');

    $team = User::where('email', 'valtest@gmail.com')->first()->personalTeam();

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
