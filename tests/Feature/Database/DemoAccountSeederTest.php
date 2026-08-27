<?php

declare(strict_types=1);

use App\Actions\Crm\GetCrmSummary;
use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\UpdateCustomField;
use App\Actions\Opportunity\AggregateOpportunities;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\Task\UpdateTask;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\AggregateOpportunitiesTool;
use App\Mcp\Tools\FetchTool;
use App\Mcp\Tools\GetCrmSchemaTool;
use App\Mcp\Tools\GetCrmSummaryTool;
use App\Mcp\Tools\ListActivityTool;
use App\Mcp\Tools\ListCustomFieldsTool;
use App\Mcp\Tools\SearchTool;
use App\Mcp\Tools\WhoAmiTool;
use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use App\Services\Billing\HostedWorkspaceAccess;
use Database\Seeders\DemoAccountSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\Fluent\AssertableJson;
use Relaticle\OnboardSeed\OnboardSeedManager;

mutates(
    AggregateOpportunities::class,
    AggregateOpportunitiesTool::class,
    CreateCustomField::class,
    DemoAccountSeeder::class,
    FetchTool::class,
    GetCrmSchemaTool::class,
    GetCrmSummary::class,
    GetCrmSummaryTool::class,
    HostedWorkspaceAccess::class,
    ListActivityTool::class,
    ListCustomFieldsTool::class,
    OnboardSeedManager::class,
    SearchTool::class,
    UpdateCustomField::class,
    UpdateOpportunity::class,
    UpdateTask::class,
    WhoAmiTool::class,
);

it('refuses to seed a reviewer account without an environment password', function (): void {
    config()->set('services.demo_account.password');

    expect(fn (): mixed => resolve(DemoAccountSeeder::class)->run())
        ->toThrow(RuntimeException::class, 'DEMO_ACCOUNT_PASSWORD is not set');

    expect(User::query()->where('email', DemoAccountSeeder::EMAIL)->exists())->toBeFalse();
});

it('seeds a deterministic reviewer workspace and safely refreshes it', function (): void {
    $this->travelTo(Date::parse('2026-08-26 12:00:00 UTC'));
    $password = 'runtime-secret-one';
    config()->set('services.demo_account.password', $password);

    $otherUser = User::factory()->withPersonalTeam()->create();
    $otherCompany = Company::factory()
        ->recycle([$otherUser, $otherUser->currentTeam])
        ->create(['name' => 'Untouched Workspace Company']);

    resolve(DemoAccountSeeder::class)->run();

    $reviewer = User::query()->where('email', DemoAccountSeeder::EMAIL)->firstOrFail();
    $team = $reviewer->currentTeam;

    expect($reviewer->email_verified_at)->not->toBeNull()
        ->and($reviewer->two_factor_secret)->toBeNull()
        ->and($reviewer->two_factor_recovery_codes)->toBeNull()
        ->and($reviewer->getAttribute('two_factor_confirmed_at'))->toBeNull()
        ->and(Hash::check($password, (string) $reviewer->password))->toBeTrue()
        ->and($team)->not->toBeNull()
        ->and($team->name)->toBe(DemoAccountSeeder::TEAM_NAME)
        ->and($team->slug)->toBe(DemoAccountSeeder::TEAM_SLUG)
        ->and($team->hosted_free_grandfathered_at)->not->toBeNull()
        ->and(resolve(HostedWorkspaceAccess::class)->allows($team))->toBeTrue();

    $notion = Company::query()->where('team_id', $team->getKey())->where('name', 'Notion')->firstOrFail();
    $ivan = People::query()->where('team_id', $team->getKey())->where('name', 'Ivan Zhao')->firstOrFail();
    $notionOpportunity = Opportunity::query()->where('team_id', $team->getKey())->where('name', 'Notion API Integration')->firstOrFail();
    $notionTask = Task::query()->where('team_id', $team->getKey())->where('title', 'Integration meeting with Ivan')->firstOrFail();
    $notionNote = Note::query()->where('team_id', $team->getKey())->where('title', 'API integration possibilities')->firstOrFail();

    expect($ivan->company_id)->toBe($notion->getKey())
        ->and($notionOpportunity->company_id)->toBe($notion->getKey())
        ->and($notionTask->people()->whereKey($ivan->getKey())->exists())->toBeTrue()
        ->and($notionNote->companies()->whereKey($notion->getKey())->exists())->toBeTrue();

    $statusField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->with('options')
        ->firstOrFail();
    $doneOptionId = $statusField->options->firstWhere('name', 'Done')?->getKey();
    $completedTask = Task::query()
        ->where('team_id', $team->getKey())
        ->where('title', 'Send proposal to Tim')
        ->with('customFieldValues.customField.options')
        ->firstOrFail();
    $unassignedTask = Task::query()
        ->where('team_id', $team->getKey())
        ->where('title', 'Discovery call with Brian')
        ->firstOrFail();

    expect($completedTask->getCustomFieldValue($statusField))->toBe($doneOptionId)
        ->and($unassignedTask->assignees()->exists())->toBeFalse()
        ->and(CustomField::query()->withoutGlobalScopes()
            ->where('tenant_id', $team->getKey())
            ->where('code', DemoAccountSeeder::INACTIVE_FIELD_CODE)
            ->where('active', false)
            ->exists())->toBeTrue()
        ->and(Activity::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBeGreaterThanOrEqual(5);

    $baseUrl = rtrim((string) config('app.url'), '/');
    $notionUrl = "{$baseUrl}/app/{$team->slug}/companies/{$notion->getKey()}";

    RelaticleServer::actingAs($reviewer)
        ->tool(WhoAmiTool::class)
        ->assertOk()
        ->assertSee(DemoAccountSeeder::TEAM_NAME)
        ->assertDontSee($password);

    RelaticleServer::actingAs($reviewer)
        ->tool(SearchTool::class, ['query' => 'Notion'])
        ->assertOk()
        ->assertSee('Notion')
        ->assertDontSee($password);

    RelaticleServer::actingAs($reviewer)
        ->tool(FetchTool::class, ['url' => $notionUrl])
        ->assertOk()
        ->assertSee('Notion')
        ->assertDontSee($password);

    RelaticleServer::actingAs($reviewer)
        ->tool(GetCrmSchemaTool::class, ['entity_type' => 'opportunity'])
        ->assertOk()
        ->assertSee('stage')
        ->assertDontSee($password);

    RelaticleServer::actingAs($reviewer)
        ->tool(GetCrmSummaryTool::class)
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('opportunities.by_stage.Closed Won.count', 1)
            ->where('tasks.overdue', 1)
            ->etc())
        ->assertDontSee($password);

    RelaticleServer::actingAs($reviewer)
        ->tool(AggregateOpportunitiesTool::class, ['group_by' => 'stage'])
        ->assertOk()
        ->assertSee('Closed Won')
        ->assertDontSee($password);

    RelaticleServer::actingAs($reviewer)
        ->tool(ListActivityTool::class, ['record_type' => 'company'])
        ->assertOk()
        ->assertSee('Notion')
        ->assertDontSee($password);

    RelaticleServer::actingAs($reviewer)
        ->tool(ListCustomFieldsTool::class, ['active' => false])
        ->assertOk()
        ->assertSee(DemoAccountSeeder::INACTIVE_FIELD_NAME)
        ->assertDontSee($password);

    $entityCounts = [
        Company::query()->where('team_id', $team->getKey())->count(),
        People::query()->where('team_id', $team->getKey())->count(),
        Opportunity::query()->where('team_id', $team->getKey())->count(),
        Task::query()->where('team_id', $team->getKey())->count(),
        Note::query()->where('team_id', $team->getKey())->count(),
    ];

    $replacementPassword = 'runtime-secret-two';
    config()->set('services.demo_account.password', $replacementPassword);
    resolve(DemoAccountSeeder::class)->run();

    $reviewer->refresh();

    expect([
        Company::query()->where('team_id', $team->getKey())->count(),
        People::query()->where('team_id', $team->getKey())->count(),
        Opportunity::query()->where('team_id', $team->getKey())->count(),
        Task::query()->where('team_id', $team->getKey())->count(),
        Note::query()->where('team_id', $team->getKey())->count(),
    ])->toBe($entityCounts)
        ->and(Hash::check($replacementPassword, (string) $reviewer->password))->toBeTrue()
        ->and(Company::query()->withoutGlobalScopes()->whereKey($otherCompany->getKey())->exists())->toBeTrue();
});

it('keeps the existing workspace slug when another team already holds the reviewer slug', function (): void {
    config()->set('services.demo_account.password', 'runtime-secret-three');

    $incumbent = Team::factory()->create(['slug' => DemoAccountSeeder::TEAM_SLUG]);

    resolve(DemoAccountSeeder::class)->run();

    $team = User::query()->where('email', DemoAccountSeeder::EMAIL)->firstOrFail()->personalTeam();

    expect($team)->not->toBeNull()
        ->and($team->name)->toBe(DemoAccountSeeder::TEAM_NAME)
        ->and($team->slug)->not->toBe(DemoAccountSeeder::TEAM_SLUG)
        ->and($team->slug)->not->toBeEmpty()
        ->and($incumbent->refresh()->slug)->toBe(DemoAccountSeeder::TEAM_SLUG);
});
