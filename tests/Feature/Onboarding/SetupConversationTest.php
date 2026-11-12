<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\Onboarding\CreateSetupConversation;
use App\Actions\Onboarding\StartDataArrivedTurn;
use App\Actions\Onboarding\StartSetupGreeting;
use App\Contracts\Entitlements;
use App\Enums\CreationSource;
use App\Enums\OnboardingUseCase;
use App\Enums\WorkspaceRole;
use App\Features\Billing;
use App\Features\SetupConversation;
use App\Filament\Pages\CreateWorkspace;
use App\Jobs\OpenDataArrivedTurn;
use App\Listeners\CreateSetupConversationListener;
use App\Listeners\StartDataArrivedTurnListener;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SelfHostedEntitlements;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Support\ConversationTitleGate;
use Relaticle\Chat\Support\ModelDescriptor;
use Relaticle\Chat\Support\TurnPresence;
use Relaticle\Cloud\Entitlements\PlanEntitlements;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHistoryImportCompletedNotification;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportOutcome;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Notifications\ImportFinishedNotification;

mutates(CreateSetupConversation::class, CreateSetupConversationListener::class, StartSetupGreeting::class, StartDataArrivedTurn::class, StartDataArrivedTurnListener::class, CreditService::class, SelfHostedEntitlements::class, PlanEntitlements::class);

beforeEach(function (): void {
    Feature::define(SetupConversation::class, true);
});

function personalWorkspaceFor(User $user, array $attributes = []): Workspace
{
    $workspace = Workspace::factory()->create([
        'user_id' => $user->getKey(),
        'personal_workspace' => true,
        ...$attributes,
    ]);

    $user->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    return $workspace->fresh();
}

function openSetupConversation(User $user, Workspace $workspace): Testable
{
    return livewire(ChatInterface::class, ['conversationId' => $workspace->setupConversation->id]);
}

it('seeds one empty setup conversation when the wizard creates a workspace', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Northwind'])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->personalWorkspace();
    $conversation = $workspace->setupConversation;

    expect($conversation)->toBeInstanceOf(AgentConversation::class)
        ->and($conversation->title)->toBe('Set up your workspace')
        ->and($conversation->participant_id)->toBe((string) $user->getKey())
        ->and($conversation->participant_type)->toBe('user')
        ->and(DB::table('agent_conversation_messages')->where('conversation_id', $conversation->id)->count())->toBe(0);
});

it('seeds nothing when the flag is off', function (): void {
    Feature::define(SetupConversation::class, false);
    $user = User::factory()->create();

    personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);

    expect(AgentConversation::query()->setup()->exists())->toBeFalse();
});

it('seeds nothing for a workspace that is not the personal one', function (): void {
    $user = User::factory()->create();

    Workspace::factory()->create(['user_id' => $user->getKey(), 'personal_workspace' => false, 'onboarding_use_case' => OnboardingUseCase::Sales]);

    expect(AgentConversation::query()->setup()->exists())->toBeFalse();
});

it('never seeds a second setup conversation for the same workspace', function (): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);

    resolve(CreateSetupConversation::class)->execute($workspace);
    resolve(CreateSetupConversation::class)->execute($workspace);

    expect(AgentConversation::query()->setup()->where('workspace_id', $workspace->getKey())->count())->toBe(1);
});

it('keeps the lang-file title when the first user message arrives', function (): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Recruiting]);

    expect(ConversationTitleGate::beforeTurn($workspace->setupConversation->id, 'Here are my candidates'))->toBeNull()
        ->and($workspace->setupConversation->fresh()->title)->toBe('Set up your workspace');
});

it('has the assistant speak first when the owner opens the setup conversation', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Recruiting]);
    $this->actingAs($user);

    openSetupConversation($user, $workspace)
        ->assertSet('turnInFlight', true)
        ->assertSet('messages', []);

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->conversationId === $workspace->setupConversation->id
        && $job->origin === MessageOrigin::Greeting);
});

it('charges the greeting turn a credit', function (): void {
    withHostedBilling();

    Queue::fake();

    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);
    $this->actingAs($user);

    openSetupConversation($user, $workspace);

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();

    expect($balance->credits_used)->toBe(1);
});

it('starts the setup greeting for a self-hosted workspace with no credits', function (): void {
    Feature::define(Billing::class, false);
    Queue::fake();

    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);
    $this->actingAs($user);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'credits_remaining' => 0,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    openSetupConversation($user, $workspace)->assertSet('turnInFlight', true);

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->origin === MessageOrigin::Greeting);
    expect(AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->value('credits_remaining'))->toBe(0);
});

it('greets once however often the thread is opened', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);
    $this->actingAs($user);

    openSetupConversation($user, $workspace);
    openSetupConversation($user, $workspace)->assertSet('turnInFlight', true);

    Queue::assertPushed(ProcessChatMessage::class, 1);
});

it('does not greet a thread that already holds messages', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);
    $this->actingAs($user);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $workspace->setupConversation->id,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => CrmAssistant::class,
        'role' => 'assistant',
        'content' => 'Welcome.',
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    openSetupConversation($user, $workspace)->assertSet('turnInFlight', false);

    Queue::assertNothingPushed();
});

it('does not greet while a turn is already running', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);
    $this->actingAs($user);

    TurnPresence::begin($workspace->setupConversation->id, turnId: (string) Str::ulid(), message: 'Here are my contacts');

    openSetupConversation($user, $workspace)->assertSet('turnInFlight', true);

    Queue::assertNothingPushed();
});

it('does not greet an ordinary conversation', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);
    $this->actingAs($user);

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Ordinary',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    livewire(ChatInterface::class, ['conversationId' => $conversationId])->assertSet('turnInFlight', false);

    Queue::assertNothingPushed();
});

it('does not greet when the flag is off', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);
    $conversationId = $workspace->setupConversation->id;
    $this->actingAs($user);

    Feature::define(SetupConversation::class, false);

    livewire(ChatInterface::class, ['conversationId' => $conversationId])->assertSet('turnInFlight', false);

    Queue::assertNothingPushed();
});

it('does not greet when the workspace is out of credits', function (): void {
    withHostedBilling();

    Queue::fake();

    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);
    $this->actingAs($user);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'credits_remaining' => 0,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    openSetupConversation($user, $workspace)->assertSet('turnInFlight', false);

    Queue::assertNothingPushed();
});

it('greets an owner who connected a mailbox with what it brought in', function (): void {
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);
    ConnectedAccount::factory()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey()]);

    People::factory()->count(30)->create(['workspace_id' => $workspace->getKey(), 'creator_id' => null, 'creation_source' => CreationSource::MAILBOX]);
    Company::factory()->count(12)->create(['workspace_id' => $workspace->getKey(), 'creator_id' => null, 'creation_source' => CreationSource::MAILBOX]);

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    CrmAssistant::fake(['Hi, your inbox brought people in.']);

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: '',
        conversationId: $workspace->setupConversation->id,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
        origin: MessageOrigin::Greeting,
    );
    $job->handle(resolve(CreditService::class));

    CrmAssistant::assertPrompted(fn ($prompt): bool => $prompt->prompt === MessageOrigin::Greeting->opener()
        && str_contains($prompt->agent->dynamicInstructions(), "mailbox: connected\n")
        && str_contains($prompt->agent->dynamicInstructions(), "arrived: 30 people and 12 companies from mailbox today\n")
        && str_contains($prompt->agent->dynamicInstructions(), "<turn>\n".MessageOrigin::Greeting->directive()."\n</turn>"));
});

function setupWorkspaceWithGreeting(User $user, array $attributes = []): Workspace
{
    $workspace = personalWorkspaceFor($user, [
        'onboarding_use_case' => OnboardingUseCase::Sales,
        ...$attributes,
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $workspace->setupConversation->id,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => CrmAssistant::class,
        'role' => 'assistant',
        'content' => 'Welcome.',
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '[]',
        'meta' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $workspace;
}

function finishedImport(User $user, Workspace $workspace, array $attributes = []): Import
{
    return Import::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'entity_type' => ImportEntityType::People,
        'status' => ImportStatus::Completed,
        'created_rows' => 12,
        ...$attributes,
    ]);
}

function endRunningTurn(Workspace $workspace): void
{
    $conversationId = $workspace->setupConversation->id;

    TurnPresence::clear($conversationId, TurnPresence::current($conversationId)['turn_id']);
}

function assertDataArrivedPushed(int $times): void
{
    Queue::assertPushed(
        ProcessChatMessage::class,
        $times,
        fn (ProcessChatMessage $job): bool => $job->origin === MessageOrigin::DataArrived,
    );
}

it('opens a data arrived turn in the setup chat when an onboarding import finishes', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->origin === MessageOrigin::DataArrived
        && $job->conversationId === $workspace->setupConversation->id
        && $job->message === '');
    expect(TurnPresence::current($workspace->setupConversation->id))->not->toBeNull();
});

it('opens a data arrived turn only in the first 14 days of a workspace', function (int $daysOld, int $turns): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    $workspace->forceFill(['created_at' => now()->subDays($daysOld)->addMinute()])->save();

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);

    assertDataArrivedPushed($turns);
})->with([
    'day 13' => [13, 1],
    'the last minute of day 14' => [14, 1],
    'day 15' => [15, 0],
]);

it('opens one data arrived turn per import', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    $import = finishedImport($user, $workspace);

    $user->notifyNow(new ImportFinishedNotification($import, ImportOutcome::Completed), ['database']);
    endRunningTurn($workspace);
    $user->notifyNow(new ImportFinishedNotification($import, ImportOutcome::Completed), ['database']);

    assertDataArrivedPushed(1);
});

it('opens a turn for a second import after the first one', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);
    endRunningTurn($workspace);
    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);

    assertDataArrivedPushed(2);
});

it('leaves the turn to the greeting when the setup chat was never opened', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user);

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('opens no data arrived turn for an import that created nothing or failed', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace, ['created_rows' => 0]), ImportOutcome::Completed), ['database']);
    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace, ['status' => ImportStatus::Failed]), ImportOutcome::Failed), ['database']);

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('opens no data arrived turn on the mail channel', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);

    $notification = new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed);
    resolve(StartDataArrivedTurnListener::class)->handle(new NotificationSent($user, $notification, 'mail'));

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('opens no data arrived turn when the flag is off', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);

    Feature::define(SetupConversation::class, false);
    Feature::purge(SetupConversation::class);
    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('opens no data arrived turn when the workspace is out of credits', function (): void {
    withHostedBilling();
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'credits_remaining' => 0,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('opens a data arrived turn when the mailbox history import completes', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey()]);

    $notification = new MailboxHistoryImportCompletedNotification($account, 'batch-1', importedEmailCount: 40, importedCalendarCount: 0, includesCalendar: false);
    $user->notifyNow($notification, ['database']);
    endRunningTurn($workspace);
    $user->notifyNow(new MailboxHistoryImportCompletedNotification($account, 'batch-1', afterFailedImportRetry: true, importedEmailCount: 40, importedCalendarCount: 0, includesCalendar: false), ['database']);

    assertDataArrivedPushed(1);
});

it('opens no data arrived turn for a mailbox that imported nothing', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    $account = ConnectedAccount::factory()->create(['workspace_id' => $workspace->getKey(), 'user_id' => $user->getKey()]);

    $user->notifyNow(new MailboxHistoryImportCompletedNotification($account, 'batch-1', importedEmailCount: 0, importedCalendarCount: 0, includesCalendar: false), ['database']);

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('opens the turn again after a pending proposal cancelled the first one', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    $import = finishedImport($user, $workspace);
    $proposal = PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $workspace->setupConversation->id,
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Acme'],
        'display_data' => ['title' => 'Create Company', 'summary' => 'Create company "Acme"', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $user->notifyNow(new ImportFinishedNotification($import, ImportOutcome::Completed), ['database']);
    Queue::pushed(ProcessChatMessage::class)->first()->handle(resolve(CreditService::class));

    expect(TurnPresence::current($workspace->setupConversation->id))->toBeNull();

    $proposal->update(['status' => PendingActionStatus::Rejected]);
    $user->notifyNow(new ImportFinishedNotification($import, ImportOutcome::Completed), ['database']);

    assertDataArrivedPushed(2);
});

it('opens the turn again after the first one failed', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    $import = finishedImport($user, $workspace);

    $user->notifyNow(new ImportFinishedNotification($import, ImportOutcome::Completed), ['database']);
    Queue::pushed(ProcessChatMessage::class)->first()->failed(new RuntimeException('provider down'));
    $user->notifyNow(new ImportFinishedNotification($import, ImportOutcome::Completed), ['database']);

    assertDataArrivedPushed(2);
});

function pendingProposalIn(Workspace $workspace, User $user): PendingAction
{
    return PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $workspace->setupConversation->id,
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => 'Acme'],
        'display_data' => ['title' => 'Create Company', 'summary' => 'Create company "Acme"', 'fields' => []],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

function runSetupChatTurn(User $user, Workspace $workspace, MessageOrigin $origin): void
{
    (new ProcessChatMessage(
        user: $user->fresh(),
        workspace: $workspace->fresh(),
        message: $origin->isTyped() ? 'Hello' : '',
        conversationId: $workspace->setupConversation->id,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-5', 'id' => 'claude-sonnet-5', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
        origin: $origin,
    ))->handle(resolve(CreditService::class));
}

it('opens the dropped data arrived turn once the owner decides the pending proposal', function (PendingActionStatus $decision): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    $proposal = pendingProposalIn($workspace, $user);

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);
    Queue::pushed(ProcessChatMessage::class)->first()->handle(resolve(CreditService::class));

    assertDataArrivedPushed(1);

    $proposal->update(['status' => $decision]);
    CrmAssistant::fake(['Done.']);
    runSetupChatTurn($user, $workspace, MessageOrigin::Resume);

    assertDataArrivedPushed(2);
    expect(TurnPresence::current($workspace->setupConversation->id)['origin'] ?? null)->toBe(MessageOrigin::DataArrived->value);
})->with([PendingActionStatus::Approved, PendingActionStatus::Rejected]);

it('keeps the dropped data arrived turn waiting while another proposal is still pending', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    pendingProposalIn($workspace, $user);

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);
    Queue::pushed(ProcessChatMessage::class)->first()->handle(resolve(CreditService::class));
    runSetupChatTurn($user, $workspace, MessageOrigin::Resume);

    assertDataArrivedPushed(1);
});

it('announces an arrival once, however many decisions follow it', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);
    CrmAssistant::fake(['Twelve people arrived.', 'Done.', 'Done again.']);
    Queue::pushed(ProcessChatMessage::class)->first()->handle(resolve(CreditService::class));

    runSetupChatTurn($user, $workspace, MessageOrigin::Resume);
    runSetupChatTurn($user, $workspace, MessageOrigin::Resume);

    assertDataArrivedPushed(1);
});

it('opens no data arrived turn after a decision when nothing arrived', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    CrmAssistant::fake(['Done.']);

    runSetupChatTurn($user, $workspace, MessageOrigin::Resume);

    assertDataArrivedPushed(0);
});

it('opens the dropped data arrived turn only after a decision, not after a typed message', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    $proposal = pendingProposalIn($workspace, $user);

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);
    Queue::pushed(ProcessChatMessage::class)->first()->handle(resolve(CreditService::class));
    $proposal->update(['status' => PendingActionStatus::Rejected]);
    CrmAssistant::fake(['Hello.']);

    runSetupChatTurn($user, $workspace, MessageOrigin::Typed);

    assertDataArrivedPushed(1);
});

it('still dispatches the data arrived turn while another turn is running in the setup chat', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    $runningTurnId = (string) Str::ulid();
    TurnPresence::begin($workspace->setupConversation->id, turnId: $runningTurnId, message: 'Here is my list');

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);

    assertDataArrivedPushed(1);
    expect(TurnPresence::current($workspace->setupConversation->id)['turn_id'])->toBe($runningTurnId);
});

it('sends the notification even when the data arrived turn cannot start', function (): void {
    Queue::fake();
    app()->bind(StartDataArrivedTurn::class, fn (): never => throw new RuntimeException('chat is down'));
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);

    Queue::assertPushed(OpenDataArrivedTurn::class);
    expect($user->notifications()->count())->toBe(1);
});

it('opens no data arrived turn for a teammate who is not the workspace owner', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $owner = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($owner);
    $teammate = User::factory()->create();
    $workspace->users()->attach($teammate, ['role' => WorkspaceRole::Admin->value]);

    $teammate->notifyNow(new ImportFinishedNotification(finishedImport($teammate, $workspace), ImportOutcome::Completed), ['database']);

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('picks the model for the setup workspace, not the owner\'s current workspace', function (): void {
    Queue::fake([ProcessChatMessage::class]);
    $seen = new ArrayObject;
    app()->instance(Entitlements::class, new class(new SelfHostedEntitlements, $seen) implements Entitlements
    {
        public function __construct(private readonly SelfHostedEntitlements $inner, private readonly ArrayObject $seen) {}

        public function metersCredits(Workspace $workspace): bool
        {
            return $this->inner->metersCredits($workspace);
        }

        public function creditAllowance(Workspace $workspace): int
        {
            return $this->inner->creditAllowance($workspace);
        }

        public function allowsModel(?Workspace $workspace, ModelDescriptor $model): bool
        {
            $this->seen->append($workspace?->getKey());

            return $this->inner->allowsModel($workspace, $model);
        }

        public function chatRateLimit(Workspace $workspace): int
        {
            return $this->inner->chatRateLimit($workspace);
        }

        public function allowsAccess(Workspace $workspace): bool
        {
            return $this->inner->allowsAccess($workspace);
        }

        public function mailboxLimit(Workspace $workspace): ?int
        {
            return $this->inner->mailboxLimit($workspace);
        }
    });
    $user = User::factory()->create();
    $workspace = setupWorkspaceWithGreeting($user);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $user->getKey()]);
    $user->forceFill(['current_workspace_id' => $otherWorkspace->getKey()])->save();

    $user->notifyNow(new ImportFinishedNotification(finishedImport($user, $workspace), ImportOutcome::Completed), ['database']);

    expect($seen->getArrayCopy())->not->toBeEmpty()
        ->and(array_unique($seen->getArrayCopy()))->toBe([$workspace->getKey()]);
});
