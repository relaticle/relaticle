<?php

declare(strict_types=1);

use App\Actions\Crm\GetCrmSummary;
use App\Actions\CustomFields\CreateCustomField;
use App\Actions\CustomFields\UpdateCustomField;
use App\Actions\Opportunity\AggregateOpportunities;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\Task\UpdateTask;
use App\Console\Commands\ResetDemoAccountCommand;
use App\Jobs\FetchFaviconForCompany;
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
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\Billing\HostedWorkspaceAccess;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\OnboardSeed\OnboardSeedManager;

mutates(
    AggregateOpportunities::class,
    AggregateOpportunitiesTool::class,
    CreateCustomField::class,
    ResetDemoAccountCommand::class,
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

it('refuses to create a reviewer account without a password', function (): void {
    $this->artisan('demo:reset')->assertFailed();

    expect(User::query()->where('email', ResetDemoAccountCommand::EMAIL)->exists())->toBeFalse();
});

it('clears reviewer leftovers and restores credits without touching another workspace', function (): void {
    $this->artisan('demo:reset', ['--password' => 'runtime-secret-four'])->assertSuccessful();

    $reviewer = User::query()->where('email', ResetDemoAccountCommand::EMAIL)->firstOrFail();
    $workspace = $reviewer->personalWorkspace();
    $otherUser = User::factory()->withPersonalWorkspace()->create();
    $otherWorkspace = $otherUser->personalWorkspace();

    $leaveLeftovers = function (Workspace $workspace, User $user): string {
        $conversationId = (string) Str::ulid();

        DB::table('agent_conversations')->insert([
            'id' => $conversationId,
            'workspace_id' => $workspace->getKey(),
            'participant_id' => $user->getKey(),
            'participant_type' => $user->getMorphClass(),
            'title' => 'Reviewer conversation',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::ulid(),
            'conversation_id' => $conversationId,
            'participant_id' => $user->getKey(),
            'participant_type' => $user->getMorphClass(),
            'agent' => 'crm',
            'role' => 'user',
            'content' => 'Delete everything please',
            'attachments' => json_encode([]),
            'tool_calls' => json_encode([]),
            'tool_results' => json_encode([]),
            'usage' => json_encode([]),
            'meta' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pending_actions')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'conversation_id' => $conversationId,
            'action_class' => 'CreateCompany',
            'operation' => 'create',
            'entity_type' => 'company',
            'action_data' => json_encode([]),
            'display_data' => json_encode([]),
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('exports')->insert([
            'id' => (string) Str::ulid(),
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'file_disk' => 'local',
            'exporter' => 'CompanyExporter',
            'total_rows' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Import::factory()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey()]);
        WorkspaceInvitation::factory()->create(['workspace_id' => $workspace->getKey()]);

        return $conversationId;
    };

    $reviewerConversationId = $leaveLeftovers($workspace, $reviewer);
    $otherConversationId = $leaveLeftovers($otherWorkspace, $otherUser);

    AiCreditBalance::query()
        ->where('workspace_id', $workspace->getKey())
        ->update(['credits_remaining' => 0, 'credits_used' => 500]);

    $this->artisan('demo:reset')->assertSuccessful();

    $leftoverTables = ['agent_conversations', 'pending_actions', 'exports', 'imports', 'workspace_invitations'];

    foreach ($leftoverTables as $table) {
        expect(DB::table($table)->where('workspace_id', $workspace->getKey())->count())->toBe(0, $table)
            ->and(DB::table($table)->where('workspace_id', $otherWorkspace->getKey())->count())->toBe(1, $table);
    }

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
    $rebuiltOpportunity = Opportunity::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('name', 'Stripe Billing Rollout')
        ->firstOrFail();
    $rebuiltContact = People::query()->whereKey($rebuiltOpportunity->contact_id)->firstOrFail();

    expect($rebuiltContact->name)->toBe('Marcus Webb')
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $reviewerConversationId)->count())->toBe(0)
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $otherConversationId)->count())->toBe(1)
        ->and($balance->credits_remaining)->toBeGreaterThan(0)
        ->and($balance->credits_used)->toBe(0);
});

it('resets the workspace without a password once the account exists', function (): void {
    $this->artisan('demo:reset', ['--password' => 'established-secret'])->assertSuccessful();

    $reviewer = User::query()->where('email', ResetDemoAccountCommand::EMAIL)->firstOrFail();
    $workspace = $reviewer->personalWorkspace();
    $reviewerCompany = Company::query()->where('workspace_id', $workspace->getKey())->firstOrFail();
    $reviewerCompany->update(['name' => 'Renamed By A Reviewer']);

    $this->artisan('demo:reset')->assertSuccessful();

    expect(Hash::check('established-secret', (string) $reviewer->refresh()->password))->toBeTrue()
        ->and(Company::query()->where('workspace_id', $workspace->getKey())->where('name', 'Renamed By A Reviewer')->exists())->toBeFalse()
        ->and(Company::query()->where('workspace_id', $workspace->getKey())->where('name', 'Notion')->exists())->toBeTrue();
});

it('creates a deterministic reviewer workspace and safely refreshes it', function (): void {
    $this->travelTo(Date::parse('2026-08-26 12:00:00 UTC'));
    $password = 'runtime-secret-one';

    $otherUser = User::factory()->withPersonalWorkspace()->create();
    $otherCompany = Company::factory()
        ->recycle([$otherUser, $otherUser->currentWorkspace])
        ->create(['name' => 'Untouched Workspace Company']);

    $this->artisan('demo:reset', ['--password' => $password])->assertSuccessful();

    $reviewer = User::query()->where('email', ResetDemoAccountCommand::EMAIL)->firstOrFail();
    $workspace = $reviewer->currentWorkspace;

    expect($reviewer->email_verified_at)->not->toBeNull()
        ->and($reviewer->two_factor_secret)->toBeNull()
        ->and($reviewer->two_factor_recovery_codes)->toBeNull()
        ->and($reviewer->getAttribute('two_factor_confirmed_at'))->toBeNull()
        ->and(Hash::check($password, (string) $reviewer->password))->toBeTrue()
        ->and($workspace)->not->toBeNull()
        ->and($workspace->name)->toBe(ResetDemoAccountCommand::WORKSPACE_NAME)
        ->and($workspace->slug)->toBe(ResetDemoAccountCommand::WORKSPACE_SLUG)
        ->and($workspace->hosted_free_grandfathered_at)->not->toBeNull()
        ->and(resolve(HostedWorkspaceAccess::class)->allows($workspace))->toBeTrue();

    $notion = Company::query()->where('workspace_id', $workspace->getKey())->where('name', 'Notion')->firstOrFail();
    $ivan = People::query()->where('workspace_id', $workspace->getKey())->where('name', 'Ivan Zhao')->firstOrFail();
    $notionOpportunity = Opportunity::query()->where('workspace_id', $workspace->getKey())->where('name', 'Notion API Integration')->firstOrFail();
    $notionTask = Task::query()->where('workspace_id', $workspace->getKey())->where('title', 'Integration meeting with Ivan')->firstOrFail();
    $notionNote = Note::query()->where('workspace_id', $workspace->getKey())->where('title', 'API integration possibilities')->firstOrFail();
    $emailField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->firstOrFail();
    $executiveEmails = People::query()
        ->where('workspace_id', $workspace->getKey())
        ->whereIn('name', ['Brian Chesky', 'Dylan Field', 'Ivan Zhao', 'Tim Cook'])
        ->withCustomFieldValues()
        ->get()
        ->mapWithKeys(fn (People $person): array => [$person->name => $person->getCustomFieldValue($emailField)])
        ->all();

    expect($ivan->company_id)->toBe($notion->getKey())
        ->and($notionOpportunity->company_id)->toBe($notion->getKey())
        ->and($notionTask->people()->whereKey($ivan->getKey())->exists())->toBeTrue()
        ->and($notionNote->companies()->whereKey($notion->getKey())->exists())->toBeTrue()
        ->and($executiveEmails)->toBe([
            'Brian Chesky' => ['brian@airbnb.example'],
            'Dylan Field' => ['dylan@figma.example'],
            'Ivan Zhao' => ['ivan@notion.example'],
            'Tim Cook' => ['tim@apple.example'],
        ]);

    $statusField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->with('options')
        ->firstOrFail();
    $doneOptionId = $statusField->options->firstWhere('name', 'Done')?->getKey();
    $completedTask = Task::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('title', 'Send proposal to Tim')
        ->with('customFieldValues.customField.options')
        ->firstOrFail();
    $unassignedTask = Task::query()
        ->where('workspace_id', $workspace->getKey())
        ->where('title', 'Discovery call with Brian')
        ->firstOrFail();

    expect($completedTask->getCustomFieldValue($statusField))->toBe($doneOptionId)
        ->and($unassignedTask->assignees()->exists())->toBeFalse()
        ->and(CustomField::query()->withoutGlobalScopes()
            ->where('tenant_id', $workspace->getKey())
            ->where('code', ResetDemoAccountCommand::INACTIVE_FIELD_CODE)
            ->where('active', false)
            ->exists())->toBeTrue()
        ->and(Activity::query()->withoutGlobalScopes()->where('workspace_id', $workspace->getKey())->count())->toBeGreaterThanOrEqual(5);

    $baseUrl = rtrim((string) config('app.url'), '/');
    $notionUrl = "{$baseUrl}/app/{$workspace->slug}/companies/{$notion->getKey()}";

    RelaticleServer::actingAs($reviewer)
        ->tool(WhoAmiTool::class)
        ->assertOk()
        ->assertSee(ResetDemoAccountCommand::WORKSPACE_NAME)
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
            ->where('opportunities.by_stage.Closed Won.count', 2)
            ->where('tasks.overdue', 4)
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
        ->assertSee(ResetDemoAccountCommand::INACTIVE_FIELD_NAME)
        ->assertDontSee($password);

    $entityCounts = [
        Company::query()->where('workspace_id', $workspace->getKey())->count(),
        People::query()->where('workspace_id', $workspace->getKey())->count(),
        Opportunity::query()->where('workspace_id', $workspace->getKey())->count(),
        Task::query()->where('workspace_id', $workspace->getKey())->count(),
        Note::query()->where('workspace_id', $workspace->getKey())->count(),
    ];

    expect($entityCounts)->toBe([20, 30, 18, 20, 12]);

    $replacementPassword = 'runtime-secret-two';
    $this->artisan('demo:reset', ['--password' => $replacementPassword])->assertSuccessful();

    $reviewer->refresh();

    expect([
        Company::query()->where('workspace_id', $workspace->getKey())->count(),
        People::query()->where('workspace_id', $workspace->getKey())->count(),
        Opportunity::query()->where('workspace_id', $workspace->getKey())->count(),
        Task::query()->where('workspace_id', $workspace->getKey())->count(),
        Note::query()->where('workspace_id', $workspace->getKey())->count(),
    ])->toBe($entityCounts)
        ->and(Hash::check($replacementPassword, (string) $reviewer->password))->toBeTrue()
        ->and(Company::query()->withoutGlobalScopes()->whereKey($otherCompany->getKey())->exists())->toBeTrue();
});

it('queues exactly one logo fetch per company, including the fixtures seeded without model events', function (): void {
    Bus::fake([FetchFaviconForCompany::class]);

    $this->artisan('demo:reset', ['--password' => 'runtime-secret-five'])->assertSuccessful();

    Bus::assertDispatched(FetchFaviconForCompany::class, fn (FetchFaviconForCompany $job): bool => $job->company->name === 'Notion');
    Bus::assertDispatchedTimes(FetchFaviconForCompany::class, 20);
});

it('removes stored logos before rebuilding the workspace', function (): void {
    Storage::fake('public');

    $this->artisan('demo:reset', ['--password' => 'runtime-secret-six'])->assertSuccessful();

    $workspace = User::query()->where('email', ResetDemoAccountCommand::EMAIL)->firstOrFail()->personalWorkspace();
    $company = Company::query()->where('workspace_id', $workspace->getKey())->where('name', 'Notion')->firstOrFail();
    $company->addMediaFromString('logo-bytes')
        ->usingFileName('logo.png')
        ->toMediaCollection(Company::LOGO_MEDIA_COLLECTION);

    expect(DB::table('media')->where('model_id', $company->getKey())->count())->toBe(1);

    $this->artisan('demo:reset')->assertSuccessful();

    expect(DB::table('media')->where('model_id', $company->getKey())->count())->toBe(0);
});

it('keeps the existing workspace slug when another workspace already holds the reviewer slug', function (): void {
    $incumbent = Workspace::factory()->create(['slug' => ResetDemoAccountCommand::WORKSPACE_SLUG]);

    $this->artisan('demo:reset', ['--password' => 'runtime-secret-three'])->assertSuccessful();

    $workspace = User::query()->where('email', ResetDemoAccountCommand::EMAIL)->firstOrFail()->personalWorkspace();

    expect($workspace)->not->toBeNull()
        ->and($workspace->name)->toBe(ResetDemoAccountCommand::WORKSPACE_NAME)
        ->and($workspace->slug)->not->toBe(ResetDemoAccountCommand::WORKSPACE_SLUG)
        ->and($workspace->slug)->not->toBeEmpty()
        ->and($incumbent->refresh()->slug)->toBe(ResetDemoAccountCommand::WORKSPACE_SLUG);
});
