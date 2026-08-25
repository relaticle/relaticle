<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\PlanReferenceResolver;
use Relaticle\Chat\Services\ProposalPlanService;
use Relaticle\Chat\Services\Tools\PlanReferenceValidator;
use Relaticle\Chat\Support\PlanReference;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;
use Relaticle\Chat\Tools\People\CreatePersonTool;
use Relaticle\Chat\Tools\Task\CreateTaskTool;

mutates(ProposalPlanService::class, PlanReferenceValidator::class, PlanReferenceResolver::class, PlanReference::class);

beforeEach(function (): void {
    Bus::fake();

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
    Auth::guard('web')->setUser($this->user);
    Filament::setTenant($this->user->currentTeam);

    $this->convId = '019dfa00-5555-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'team_id' => $this->user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->turnId = '01TURNAAAAAAAAAAAAAAAAAAAA';

    $this->tool = function (string $class, ?string $turnId = null) {
        $tool = resolve($class);
        $tool->setConversationId($this->convId);
        $tool->setTurnId($turnId ?? $this->turnId);

        return $tool;
    };

    $this->proposalFor = fn (string $entityType): PendingAction => PendingAction::query()
        ->where('conversation_id', $this->convId)
        ->where('entity_type', $entityType)
        ->latest('id')
        ->firstOrFail();
});

it('groups the writes of one turn into a single plan and links them by reference', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics', 'domains' => ['acmerobotics.io']]],
    ]));

    $company = ($this->proposalFor)('company');

    ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())]],
    ]));

    $person = ($this->proposalFor)('people');
    $plan = resolve(ProposalPlanService::class);

    expect($plan->steps($company))->toHaveCount(2)
        ->and($plan->isPlan($person))->toBeTrue()
        ->and($plan->dependencyIds($person))->toBe([(string) $company->getKey()])
        ->and($plan->unmetDependencies($person, $plan->steps($person)))->toHaveCount(1);
});

it('shows the referenced record on the card instead of dropping the row', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics']],
    ]));

    $company = ($this->proposalFor)('company');

    ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())]],
    ]));

    $fields = collect(($this->proposalFor)('people')->display_data['fields'] ?? []);

    expect($fields->firstWhere('label', 'Company')['value'] ?? null)->toBe('Acme Robotics (step 1)');
});

it('approves the whole plan in order and resolves references to real ids', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics']],
    ]));

    $company = ($this->proposalFor)('company');

    ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())]],
    ]));

    $person = ($this->proposalFor)('people');

    ($this->tool)(CreateTaskTool::class)->handle(new Request([
        'records' => [['title' => 'Call Jane', 'people_ids' => [PlanReference::to((string) $person->getKey())]]],
    ]));

    $result = resolve(ProposalPlanService::class)->approveAll($company, $this->user);

    expect($result['approved'])->toBe(3)
        ->and($result['failed'])->toBeNull();

    $createdCompany = Company::query()->where('name', 'Acme Robotics')->firstOrFail();
    $createdPerson = People::query()->where('name', 'Jane Doe')->firstOrFail();

    expect((string) $createdPerson->company_id)->toBe((string) $createdCompany->getKey())
        ->and($createdPerson->tasks()->pluck('tasks.title')->all())->toContain('Call Jane');
});

it('cancels the steps that depended on a rejected one', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics']],
    ]));

    $company = ($this->proposalFor)('company');

    ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())]],
    ]));

    $person = ($this->proposalFor)('people');

    ($this->tool)(CreateTaskTool::class)->handle(new Request([
        'records' => [['title' => 'Call Jane', 'people_ids' => [PlanReference::to((string) $person->getKey())]]],
    ]));

    $cancelled = resolve(ProposalPlanService::class)->reject($company, $this->user);

    expect($cancelled)->toHaveCount(2)
        ->and($company->refresh()->status)->toBe(PendingActionStatus::Rejected)
        ->and($person->refresh()->status)->toBe(PendingActionStatus::Rejected)
        ->and($person->result_data['cancelled_by'] ?? null)->toBe((string) $company->getKey())
        ->and(Company::query()->count())->toBe(0)
        ->and(People::query()->count())->toBe(0);
});

it('refuses to approve a step whose reference was never approved', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics']],
    ]));

    $company = ($this->proposalFor)('company');

    ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())]],
    ]));

    $person = ($this->proposalFor)('people');

    resolve(PendingActionService::class)->reject($company, $this->user);

    expect(fn () => resolve(PendingActionService::class)->approve($person->refresh(), $this->user))
        ->toThrow(RuntimeException::class);

    expect(People::query()->count())->toBe(0);
});

it('reports the failing step and keeps the steps already committed', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics']],
    ]));

    $company = ($this->proposalFor)('company');

    ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())]],
    ]));

    // The second step's dependency vanishes between proposal and approval.
    $person = ($this->proposalFor)('people');
    $person->update(['action_data' => [...$person->action_data, 'company_id' => PlanReference::to('01MISSINGMISSINGMISSINGMI')]]);

    $result = resolve(ProposalPlanService::class)->approveAll($company, $this->user);

    expect($result['approved'])->toBe(1)
        ->and($result['failed']['step'] ?? null)->toBe(2)
        ->and(Company::query()->where('name', 'Acme Robotics')->exists())->toBeTrue()
        ->and(People::query()->count())->toBe(0);
});

it('treats a lone proposal as a plan of one', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Solo Corp']],
    ]));

    $company = ($this->proposalFor)('company');
    $plan = resolve(ProposalPlanService::class);

    expect($plan->isPlan($company))->toBeFalse()
        ->and($plan->steps($company))->toHaveCount(1)
        ->and($plan->unmetDependencies($company, $plan->steps($company)))->toBe([]);

    $result = $plan->approveAll($company, $this->user);

    expect($result['approved'])->toBe(1)
        ->and(Company::query()->where('name', 'Solo Corp')->exists())->toBeTrue();
});

describe('reference validation', function (): void {
    it('rejects a reference to a proposal from another turn', function (): void {
        ($this->tool)(CreateCompanyTool::class, '01OTHERTURNAAAAAAAAAAAAAAA')->handle(new Request([
            'records' => [['name' => 'Acme Robotics']],
        ]));

        $company = ($this->proposalFor)('company');

        $result = ($this->tool)(CreatePersonTool::class)->handle(new Request([
            'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())]],
        ]));

        expect($result)->toContain('Unknown step reference')
            ->and(PendingAction::query()->where('entity_type', 'people')->count())->toBe(0);
    });

    it('rejects a reference to the wrong entity type', function (): void {
        ($this->tool)(CreatePersonTool::class)->handle(new Request([
            'records' => [['name' => 'Jane Doe']],
        ]));

        $person = ($this->proposalFor)('people');

        $result = ($this->tool)(CreatePersonTool::class)->handle(new Request([
            'records' => [['name' => 'John Roe', 'company_id' => PlanReference::to((string) $person->getKey())]],
        ]));

        expect($result)->toContain('but a Company is required here');
    });

    it('rejects a reference to a multi-record proposal', function (): void {
        ($this->tool)(CreateCompanyTool::class)->handle(new Request([
            'records' => [['name' => 'Acme Robotics'], ['name' => 'Globex']],
        ]));

        $companies = ($this->proposalFor)('company');

        $result = ($this->tool)(CreatePersonTool::class)->handle(new Request([
            'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $companies->getKey())]],
        ]));

        expect($result)->toContain('ambiguous');
    });

    it('rejects a reference to an already decided proposal', function (): void {
        ($this->tool)(CreateCompanyTool::class)->handle(new Request([
            'records' => [['name' => 'Acme Robotics']],
        ]));

        $company = ($this->proposalFor)('company');
        resolve(PendingActionService::class)->approve($company, $this->user);

        $result = ($this->tool)(CreatePersonTool::class)->handle(new Request([
            'records' => [['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())]],
        ]));

        expect($result)->toContain('already approved');
    });

    it('rejects an invented reference', function (): void {
        $result = ($this->tool)(CreatePersonTool::class)->handle(new Request([
            'records' => [['name' => 'Jane Doe', 'company_id' => '$ref:01NOPENOPENOPENOPENOPENOPE']],
        ]));

        expect($result)->toContain('Unknown step reference');
    });

    it('stops chaining past the step limit', function (): void {
        config()->set('chat.max_plan_steps', 2);

        ($this->tool)(CreateCompanyTool::class)->handle(new Request(['records' => [['name' => 'One']]]));
        ($this->tool)(CreateCompanyTool::class)->handle(new Request(['records' => [['name' => 'Two']]]));

        $result = ($this->tool)(CreateCompanyTool::class)->handle(new Request(['records' => [['name' => 'Three']]]));

        expect($result)->toContain('maximum for one approval')
            ->and(PendingAction::query()->where('entity_type', 'company')->count())->toBe(2);
    });
});

it('does not surface driver detail when a plan step hits a database error', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics']],
    ]));

    $step = ($this->proposalFor)('company');

    // A real driver error, not a stubbed one: companies.name is varchar(255), so
    // this insert fails inside the approval transaction with SQLSTATE 22001 and a
    // message carrying the SQL, the bound value and the connection name.
    $secret = str_repeat('SecretCorp', 40);
    $step->update(['action_data' => [...$step->action_data, 'name' => $secret]]);

    $result = resolve(ProposalPlanService::class)->approveAll($step->fresh(), $this->user);

    expect($result['failed'])->not->toBeNull()
        ->and($result['failed']['message'])->not->toContain('insert into')
        ->and($result['failed']['message'])->not->toContain($secret)
        ->and($result['failed']['message'])->not->toContain('SQLSTATE')
        ->and($result['failed']['message'])->not->toContain('pgsql');
});

it('renders a plan without re-reading the plan or the custom fields once per step', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics']],
    ]));
    $company = ($this->proposalFor)('company');

    // Four people steps: the same entity type repeated, which is what a chained
    // "create the company, then its contacts" request actually produces.
    foreach (['Jane Doe', 'John Roe', 'Ada Lovelace', 'Grace Hopper'] as $name) {
        ($this->tool)(CreatePersonTool::class)->handle(new Request([
            'records' => [['name' => $name, 'company_id' => PlanReference::to((string) $company->getKey())]],
        ]));
    }

    $card = Livewire::test(ProposalCard::class, [
        'pendingActionId' => (string) $company->getKey(),
        'context' => 'chat',
    ]);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $card->call('$refresh');

    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    $planQueries = $queries->filter(fn (string $q): bool => str_contains($q, 'from "pending_actions"'))->count();
    $customFieldQueries = $queries->filter(fn (string $q): bool => str_contains($q, 'from "custom_fields"'))->count();

    // Five steps over two entity types. One plan read, and one custom-field read
    // per distinct entity type (plus its options eager load), not per step.
    expect($planQueries)->toBeLessThanOrEqual(3, "plan re-read per step: {$planQueries} pending_actions queries")
        ->and($customFieldQueries)->toBeLessThanOrEqual(4, "custom fields re-read per step: {$customFieldQueries} custom_fields queries");
});

it('lists every record of a batch step the dock is not focused on before approving the plan', function (): void {
    ($this->tool)(CreateCompanyTool::class)->handle(new Request([
        'records' => [['name' => 'Acme Robotics']],
    ]));
    $company = ($this->proposalFor)('company');

    // Step 2 proposes three people at once. The dock focuses step 1 on mount, so
    // without every record listed as a row the user approves Ada and Grace sight
    // unseen.
    ($this->tool)(CreatePersonTool::class)->handle(new Request([
        'records' => [
            ['name' => 'Jane Doe', 'company_id' => PlanReference::to((string) $company->getKey())],
            ['name' => 'Ada Lovelace', 'company_id' => PlanReference::to((string) $company->getKey())],
            ['name' => 'Grace Hopper', 'company_id' => PlanReference::to((string) $company->getKey())],
        ],
    ]));
    $people = ($this->proposalFor)('people');

    $card = Livewire::test(ProposalCard::class, [
        'pendingActionId' => (string) $company->getKey(),
        'context' => 'chat',
    ]);

    $card->assertSee('Jane Doe')
        ->assertSee('Ada Lovelace')
        ->assertSee('Grace Hopper')
        // The rows have to be interactive for step 2, not just visible: a step
        // the dock never focuses would otherwise have no way to open record 2.
        ->assertSee("focusItem('".$people->getKey()."', 1)", escape: false)
        ->assertSee("skipItem('".$people->getKey()."', 1)", escape: false);

    $card->call('focusItem', (string) $people->getKey(), 1)
        ->assertSet('activeStepId', (string) $people->getKey())
        ->assertSee('Ada Lovelace')
        ->assertSet('cursor', 1);
});
