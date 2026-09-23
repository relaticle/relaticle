<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\People\CreatePeople;
use App\Enums\Plan;
use App\Features\OnboardSeed;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\MessageOrigin;
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
use Tests\Helpers\AnthropicSse;

mutates(TurnContinuationService::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);

    $this->user = User::factory()->withPersonalWorkspace()->create();
    Auth::guard('web')->setUser($this->user);
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);

    $this->convId = '019df900-9999-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $this->convId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'workspace_id' => $this->user->currentWorkspace->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    AiCreditBalance::query()->updateOrCreate(
        ['workspace_id' => $this->user->currentWorkspace->getKey()],
        ['credits_remaining' => 50, 'credits_used' => 0, 'purchased_credits' => 0],
    );
});

function continuationProposal(User $user, string $conversationId, string $turnId, string $name): PendingAction
{
    return PendingAction::query()->create([
        'workspace_id' => $user->currentWorkspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'turn_id' => $turnId,
        'action_class' => CreateCompany::class,
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
        fn (ProcessChatMessage $job): bool => $job->origin === MessageOrigin::Resume
            && $job->conversationId === $this->convId
            && $job->message === "The user decided the proposals above:\n- APPROVED (written): create company \"Continuation Co\"",
    );
});

it('resumes after a rejection too, so a discarded card is not a dead end', function (): void {
    Queue::fake();

    $turnId = (string) Str::ulid();
    $proposal = continuationProposal($this->user, $this->convId, $turnId, 'Rejected Co');

    Livewire::test(ProposalCard::class)
        ->dispatch('proposal:set-active', id: (string) $proposal->getKey(), context: 'conversation')
        ->call('discardCurrent', resolve(PendingActionService::class));

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => $job->origin === MessageOrigin::Resume
            && $job->message === "The user decided the proposals above:\n- REJECTED (nothing was written): create company \"Rejected Co\"",
    );
});

it('opens the resumed turn with every decision of the turn, skipped records included', function (): void {
    Queue::fake();

    $turnId = (string) Str::ulid();
    continuationProposal($this->user, $this->convId, $turnId, 'Decided Co')
        ->forceFill(['status' => PendingActionStatus::Approved, 'resolved_at' => now(), 'result_data' => ['id' => '01cc0000000000000000000000', 'type' => 'company']])
        ->save();
    PendingAction::query()->create([
        'workspace_id' => $this->user->currentWorkspace->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'turn_id' => $turnId,
        'action_class' => CreatePeople::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => 'people',
        'action_data' => ['_batch' => true, 'records' => [['name' => 'Ivan Zhao'], ['name' => 'Simon Last']]],
        'display_data' => ['title' => 'Create 2 people', 'summary' => 'Create 2 people', 'items' => []],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now()->addSecond(),
        'result_data' => [
            'items' => ['0' => ['status' => 'approved', 'id' => '01aa0000000000000000000000'], '1' => ['status' => 'rejected']],
            'ids' => ['01aa0000000000000000000000'],
            'type' => 'people',
            'count' => 1,
        ],
    ]);
    continuationProposal($this->user, $this->convId, (string) Str::ulid(), 'Earlier Co')
        ->forceFill(['status' => PendingActionStatus::Rejected, 'resolved_at' => now()->subMinute()])
        ->save();

    resolve(TurnContinuationService::class)->resume($this->user, $this->convId, $turnId);

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => $job->message === implode("\n", [
            'The user decided the proposals above:',
            '- APPROVED (written): create company "Decided Co"',
            '- APPROVED (written): create 1 people records:',
            '    - "Ivan Zhao"',
            '    - skipped by the user, NOT created: "Simon Last"',
        ]),
    );
});

it('falls back to a plain opener when the resumed turn has no decided proposals', function (): void {
    Queue::fake();

    resolve(TurnContinuationService::class)->resume($this->user, $this->convId, (string) Str::ulid());

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => $job->message === 'The user decided the proposals above.',
    );
});

it('names an approved delete in the resumed turn by label, without the ids it removed', function (): void {
    Queue::fake();

    $turnId = (string) Str::ulid();
    PendingAction::query()->create([
        'workspace_id' => $this->user->currentWorkspace->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => $this->convId,
        'turn_id' => $turnId,
        'action_class' => CreateCompany::class,
        'operation' => PendingActionOperation::Delete,
        'entity_type' => 'company',
        'action_data' => ['_record_ids' => ['01dd0000000000000000000001', '01dd0000000000000000000002']],
        'display_data' => ['title' => 'Delete 2 companies', 'summary' => 'Delete 2 companies'],
        'status' => PendingActionStatus::Approved,
        'expires_at' => now()->addMinutes(15),
        'resolved_at' => now(),
        'result_data' => ['ids' => ['01dd0000000000000000000001', '01dd0000000000000000000002'], 'type' => 'company'],
    ]);

    resolve(TurnContinuationService::class)->resume($this->user, $this->convId, $turnId);

    Queue::assertPushed(
        ProcessChatMessage::class,
        fn (ProcessChatMessage $job): bool => str_starts_with($job->message, "The user decided the proposals above:\n- APPROVED (written): delete company ")
            && ! str_contains($job->message, '01dd0000000000000000000001'),
    );
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
        'workspace' => $this->user->currentWorkspace,
        'message' => '',
        'conversationId' => $this->convId,
        'resolved' => resolve(AiModelResolver::class)->resolve($this->user),
        'turnId' => (string) Str::ulid(),
        'origin' => MessageOrigin::Resume,
        'resumesTurnId' => $turnId,
    ])->handle(resolve(CreditService::class));

    // Deciding the step that blocked it must still resume the assistant.
    PendingAction::query()->where('turn_id', $turnId)->update(['status' => PendingActionStatus::Approved]);

    expect($service->resume($this->user, $this->convId, $turnId))->toBeTrue();
});

it('skips the resume when the workspace is out of credits', function (): void {
    Queue::fake();

    AiCreditBalance::query()
        ->where('workspace_id', $this->user->currentWorkspace->getKey())
        ->update(['credits_remaining' => 0]);

    $queued = resolve(TurnContinuationService::class)
        ->resume($this->user, $this->convId, (string) Str::ulid());

    expect($queued)->toBeFalse();
    Queue::assertNotPushed(ProcessChatMessage::class);
});

it('charges one credit for the resumed turn', function (): void {
    Queue::fake();

    $before = AiCreditBalance::query()->where('workspace_id', $this->user->currentWorkspace->getKey())->value('credits_remaining');

    resolve(TurnContinuationService::class)->resume($this->user, $this->convId, (string) Str::ulid());

    $after = AiCreditBalance::query()->where('workspace_id', $this->user->currentWorkspace->getKey())->value('credits_remaining');

    expect($after)->toBe($before - 1);
});

it('hides the resumed turn prompt from the transcript but keeps every other message', function (): void {
    $rows = [
        ['role' => 'user', 'content' => 'Create a company', 'origin' => MessageOrigin::Typed],
        ['role' => 'assistant', 'content' => 'Review the proposal below.', 'origin' => MessageOrigin::Typed],
        ['role' => 'user', 'content' => 'The user decided the proposals above.', 'origin' => MessageOrigin::Resume],
        ['role' => 'assistant', 'content' => 'Created it.', 'origin' => MessageOrigin::Typed],
    ];

    foreach ($rows as $index => $row) {
        DB::table('agent_conversation_messages')->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $this->convId,
            'participant_type' => 'user',
            'participant_id' => (string) $this->user->getKey(),
            'agent' => CrmAssistant::class,
            'role' => $row['role'],
            'content' => $row['content'],
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
            'origin' => $row['origin']->value,
            'created_at' => now()->addSeconds($index),
            'updated_at' => now()->addSeconds($index),
        ]);
    }

    $messages = resolve(ListConversationMessages::class)->execute($this->user, $this->convId);

    expect($messages)->toHaveCount(3)
        ->and(array_column($messages, 'role'))->toBe(['user', 'assistant', 'assistant'])
        ->and(collect($messages)->pluck('content')->implode(' '))->not->toContain('decided the proposals above');
});

it('leaves the next job on the worker to store its own question as the user typed it', function (): void {
    $workspace = $this->user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(AnthropicSse::TERMINAL_ERROR, 200, ['Content-Type' => 'text/event-stream'])
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
        new ProcessChatMessage(
            user: $this->user,
            workspace: $workspace,
            message: 'The user decided the proposals above.',
            conversationId: $this->convId,
            resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
            turnId: (string) Str::ulid(),
            origin: MessageOrigin::Resume,
        )->handle(resolve(CreditService::class));
    } catch (Throwable) {
        // Premise, not subject.
    }

    // The very next job the worker picks up, with its own question.
    new ProcessChatMessage(
        user: $this->user,
        workspace: $workspace,
        message: 'What did we agree with Acme?',
        conversationId: $this->convId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
    )->handle(resolve(CreditService::class));

    $stored = DB::table('agent_conversation_messages')
        ->where('conversation_id', $this->convId)
        ->where('role', 'user')->latest()
        ->first();

    expect($stored)->not->toBeNull()
        ->and($stored->content)->toContain('Acme')
        ->and($stored->origin)->toBe(MessageOrigin::Typed->value);

    $transcript = resolve(ListConversationMessages::class)->execute($this->user, $this->convId);

    expect(collect($transcript)->pluck('content')->implode(' '))->toContain('What did we agree with Acme?');
});

it('saves a resumed turn as its opener with a resume origin and keeps it out of the transcript', function (): void {
    $workspace = $this->user->currentWorkspace;
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    CrmAssistant::fake(['Created it.']);
    Queue::fake();

    new ProcessChatMessage(
        user: $this->user,
        workspace: $workspace,
        message: "The user decided the proposals above:\n- REJECTED (nothing was written): create company \"Rejected Co\"",
        conversationId: $this->convId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
        turnId: (string) Str::ulid(),
        origin: MessageOrigin::Resume,
    )->handle(resolve(CreditService::class));

    $row = DB::table('agent_conversation_messages')
        ->where('conversation_id', $this->convId)
        ->where('role', 'user')
        ->sole();

    expect($row->origin)->toBe(MessageOrigin::Resume->value)
        ->and($row->content)->toBe("The user decided the proposals above:\n- REJECTED (nothing was written): create company \"Rejected Co\"")
        ->and(array_column(resolve(ListConversationMessages::class)->execute($this->user, $this->convId), 'role'))
        ->toBe(['assistant']);

    CrmAssistant::assertPrompted(fn ($prompt): bool => $prompt->prompt === $row->content
        && str_contains($prompt->agent->dynamicInstructions(), "The latest user message is the system's record of each decision"));
});
