<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Features\OnboardSeed;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\ProposalPlanService;
use Relaticle\Chat\Services\TurnContinuationService;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;

uses(LazilyRefreshDatabase::class);

mutates(TurnContinuationService::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);

    $this->user = User::factory()->withPersonalTeam()->create();
    Auth::guard('web')->setUser($this->user);
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentTeam);

    $this->convId = '019df900-9999-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'team_id' => $this->user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    AiCreditBalance::query()->updateOrCreate(
        ['team_id' => $this->user->currentTeam->getKey()],
        ['credits_remaining' => 50, 'credits_used' => 0, 'purchased_credits' => 0],
    );
});

function continuationProposal(User $user, string $conversationId, string $turnId, string $name): PendingAction
{
    return PendingAction::query()->create([
        'team_id' => $user->currentTeam->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'turn_id' => $turnId,
        'action_class' => 'App\\Actions\\Company\\CreateCompany',
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'company',
        'action_data' => ['name' => $name],
        'display_data' => [
            'title' => 'Create Company',
            'summary' => "Create company \"{$name}\"",
            'fields' => [['label' => 'Name', 'code' => 'name', 'value' => $name]],
        ],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

it('resumes the assistant when an approval leaves nothing pending', function (): void {
    Queue::fake();

    $turnId = (string) Str::ulid();
    $proposal = continuationProposal($this->user, $this->convId, $turnId, 'Continuation Co');

    Livewire::test(ProposalCard::class)
        ->dispatch('proposal:set-active', id: (string) $proposal->getKey(), context: 'conversation')
        ->call('createCurrent', resolve(PendingActionService::class));

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => $job->isContinuation
            && $job->conversationId === $this->convId
            && $job->message === TurnContinuationService::PROMPT,
    );
});

it('resumes after a rejection too, so a discarded card is not a dead end', function (): void {
    Queue::fake();

    $turnId = (string) Str::ulid();
    $proposal = continuationProposal($this->user, $this->convId, $turnId, 'Rejected Co');

    Livewire::test(ProposalCard::class)
        ->dispatch('proposal:set-active', id: (string) $proposal->getKey(), context: 'conversation')
        ->call('discardCurrent', resolve(PendingActionService::class));

    Queue::assertPushed(ProcessChatMessage::class, fn (ProcessChatMessage $job): bool => $job->isContinuation);
});

it('does not resume while another step of the plan is still pending', function (): void {
    Queue::fake();

    $turnId = (string) Str::ulid();
    $first = continuationProposal($this->user, $this->convId, $turnId, 'Step One Co');
    continuationProposal($this->user, $this->convId, $turnId, 'Step Two Co');

    Livewire::test(ProposalCard::class)
        ->dispatch('proposal:set-active', id: (string) $first->getKey(), context: 'conversation')
        ->call('approveStep', (string) $first->getKey(), resolve(ProposalPlanService::class));

    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('resumes once per decided turn, however many times the resolution is replayed', function (): void {
    Queue::fake();

    $turnId = (string) Str::ulid();
    continuationProposal($this->user, $this->convId, $turnId, 'Once Co')
        ->update(['status' => PendingActionStatus::Approved]);

    $service = resolve(TurnContinuationService::class);

    expect($service->resume($this->user, $this->convId, $turnId))->toBeTrue()
        ->and($service->resume($this->user, $this->convId, $turnId))->toBeFalse();

    Queue::assertPushed(ProcessChatMessage::class, 1);
});

it('hands the resume back when the queued turn finds a step still pending', function (): void {
    Queue::fake();

    // Approve-mid-stream: the steps of a chained turn share one turn_id, and
    // step 2 has not streamed in yet when step 1 is approved.
    $turnId = (string) Str::ulid();
    $service = resolve(TurnContinuationService::class);

    expect($service->resume($this->user, $this->convId, $turnId))->toBeTrue();

    // Step 2 lands, so the queued job refuses to run.
    continuationProposal($this->user, $this->convId, $turnId, 'Late Step Co');

    resolve(ProcessChatMessage::class, [
        'user' => $this->user,
        'team' => $this->user->currentTeam,
        'message' => TurnContinuationService::PROMPT,
        'conversationId' => $this->convId,
        'resolved' => resolve(AiModelResolver::class)->resolve($this->user),
        'turnId' => (string) Str::ulid(),
        'isContinuation' => true,
        'resumesTurnId' => $turnId,
    ])->handle(resolve(CreditService::class));

    // Deciding the step that blocked it must still resume the assistant.
    PendingAction::query()->where('turn_id', $turnId)->update(['status' => PendingActionStatus::Approved]);

    expect($service->resume($this->user, $this->convId, $turnId))->toBeTrue();
});

it('skips the resume when the workspace is out of credits', function (): void {
    Queue::fake();

    AiCreditBalance::query()
        ->where('team_id', $this->user->currentTeam->getKey())
        ->update(['credits_remaining' => 0]);

    $queued = resolve(TurnContinuationService::class)
        ->resume($this->user, $this->convId, (string) Str::ulid());

    expect($queued)->toBeFalse();
    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('charges one credit for the resumed turn', function (): void {
    Queue::fake();

    $before = AiCreditBalance::query()->where('team_id', $this->user->currentTeam->getKey())->value('credits_remaining');

    resolve(TurnContinuationService::class)->resume($this->user, $this->convId, (string) Str::ulid());

    $after = AiCreditBalance::query()->where('team_id', $this->user->currentTeam->getKey())->value('credits_remaining');

    expect($after)->toBe($before - 1);
});

it('hides the resumed turn prompt from the transcript but keeps every other message', function (): void {
    $rows = [
        ['role' => 'user', 'content' => 'Create a company', 'meta' => '[]'],
        ['role' => 'assistant', 'content' => 'Review the proposal below.', 'meta' => '{"model": "claude-sonnet-4-6"}'],
        ['role' => 'user', 'content' => TurnContinuationService::PROMPT, 'meta' => json_encode(['kind' => SupersededAwareConversationStore::CONTINUATION_KIND], JSON_THROW_ON_ERROR)],
        ['role' => 'assistant', 'content' => 'Created it.', 'meta' => '{"model": "claude-sonnet-4-6"}'],
    ];

    foreach ($rows as $index => $row) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $this->convId,
            'participant_type' => 'user',
            'participant_id' => (string) $this->user->getKey(),
            'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
            'role' => $row['role'],
            'content' => $row['content'],
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => $row['meta'],
            'created_at' => now()->addSeconds($index),
            'updated_at' => now()->addSeconds($index),
        ]);
    }

    $messages = resolve(ListConversationMessages::class)->execute($this->user, $this->convId);

    expect($messages)->toHaveCount(3)
        ->and(array_column($messages, 'role'))->toBe(['user', 'assistant', 'assistant'])
        ->and(collect($messages)->pluck('content')->implode(' '))->not->toContain('their outcome is in');
});

it('clears the continuation flag when the resumed turn dies before it stores anything', function (): void {
    $team = $this->user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro])->save();

    Http::fake([
        'api.anthropic.com/*' => Http::response(
            "data: {\"type\":\"error\",\"error\":{\"type\":\"invalid_request_error\",\"message\":\"bad request\"}}\n\n",
            200,
            ['Content-Type' => 'text/event-stream'],
        ),
    ]);
    Queue::fake();

    $job = new ProcessChatMessage(
        user: $this->user,
        team: $team,
        message: TurnContinuationService::PROMPT,
        conversationId: $this->convId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
        isContinuation: true,
    );

    try {
        $job->handle(resolve(CreditService::class));
    } catch (Throwable) {
        // The turn dying is the premise; the flag it leaves behind is the subject.
    }

    expect(resolve(ConversationStore::class)->nextUserMessageIsContinuation)->toBeFalse();
});

it('leaves the next job on the worker to store its own question as the user typed it', function (): void {
    $team = $this->user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro])->save();

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push("data: {\"type\":\"error\",\"error\":{\"type\":\"invalid_request_error\",\"message\":\"bad request\"}}\n\n", 200, ['Content-Type' => 'text/event-stream'])
            ->push(implode("\n\n", [
                'data: {"type":"message_start","message":{"id":"msg_1","model":"claude-sonnet-4-6","usage":{"input_tokens":5}}}',
                'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"They agreed to a pilot."}}',
                'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"},"usage":{"output_tokens":7}}',
                'data: {"type":"message_stop"}',
            ])."\n\n", 200, ['Content-Type' => 'text/event-stream']),
    ]);
    Queue::fake();

    // A resumed turn that never reaches its own write.
    try {
        (new ProcessChatMessage(
            user: $this->user,
            team: $team,
            message: TurnContinuationService::PROMPT,
            conversationId: $this->convId,
            resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
            turnId: (string) Str::ulid(),
            isContinuation: true,
        ))->handle(resolve(CreditService::class));
    } catch (Throwable) {
        // Premise, not subject.
    }

    // The very next job the worker picks up, with its own question.
    (new ProcessChatMessage(
        user: $this->user,
        team: $team,
        message: 'What did we agree with Acme?',
        conversationId: $this->convId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
    ))->handle(resolve(CreditService::class));

    $stored = DB::table('agent_conversation_messages')
        ->where('conversation_id', $this->convId)
        ->where('role', 'user')
        ->orderByDesc('created_at')
        ->first();

    expect($stored)->not->toBeNull()
        ->and($stored->content)->toContain('Acme')
        ->and((string) $stored->meta)->not->toContain(SupersededAwareConversationStore::CONTINUATION_KIND);

    $transcript = resolve(ListConversationMessages::class)->execute($this->user, $this->convId);

    expect(collect($transcript)->pluck('content')->implode(' '))->toContain('What did we agree with Acme?');
});
