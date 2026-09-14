<?php

declare(strict_types=1);

use App\Actions\Onboarding\CreateSetupConversation;
use App\Actions\Onboarding\StartSetupGreeting;
use App\Enums\OnboardingUseCase;
use App\Features\SetupConversation;
use App\Filament\Pages\CreateWorkspace;
use App\Listeners\CreateSetupConversationListener;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Support\ConversationTitleGate;
use Relaticle\Chat\Support\TurnPresence;

mutates(CreateSetupConversation::class, CreateSetupConversationListener::class, StartSetupGreeting::class);

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
        ->fillForm([
            'name' => 'Northwind',
            'onboarding_use_case' => OnboardingUseCase::Recruiting->value,
            'onboarding_context' => ['sourcing'],
        ])
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
        && $job->message === StartSetupGreeting::PROMPT
        && $job->isContinuation);
});

it('charges the greeting turn a credit', function (): void {
    Queue::fake();

    $user = User::factory()->create();
    $workspace = personalWorkspaceFor($user, ['onboarding_use_case' => OnboardingUseCase::Sales]);
    $this->actingAs($user);

    openSetupConversation($user, $workspace);

    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->first();

    expect($balance->credits_used)->toBe(1);
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
        'tool_calls' => '[]',
        'tool_results' => '[]',
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
