<?php

declare(strict_types=1);

namespace Relaticle\Chat\Jobs;

use App\Models\Team;
use App\Models\User;
use App\Services\Billing\HostedWorkspaceAccess;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Events\ChatStreamRetrying;
use Relaticle\Chat\Events\ConversationResolved;
use Relaticle\Chat\Events\FollowUpsSuggested;
use Relaticle\Chat\Events\PendingActionsSuperseded;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\FollowUpService;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\TipTapDocumentParser;
use Relaticle\Chat\Support\AssistantText;
use Relaticle\Chat\Support\ChatTelemetry;
use Relaticle\Chat\Support\ProviderRateGate;
use Relaticle\Chat\Support\ProviderStreamError;
use Relaticle\Chat\Support\StreamEventBroadcaster;
use Throwable;

#[Timeout(self::TIMEOUT_SECONDS)]
#[MaxExceptions(1)]
final class ProcessChatMessage implements ShouldQueue
{
    use Queueable;

    private const int MAX_RATE_LIMIT_RETRIES = 5;

    private const int TIMEOUT_SECONDS = 120;

    /**
     * @param  array{provider: string|null, model: string|null}  $resolved
     * @param  list<array{type: string, id: string, label: string}>  $mentions
     * @param  array<string, mixed>  $document
     */
    public function __construct(
        private readonly User $user,
        private readonly Team $team,
        public readonly string $message,
        public readonly string $conversationId,
        private readonly array $resolved,
        public readonly array $mentions = [],
        public readonly array $document = ['type' => 'doc', 'content' => []],
        public readonly string $turnId = '',
    ) {
        $this->onQueue('chat');
        $this->afterCommit = true;
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(3);
    }

    /**
     * One streaming turn per conversation at a time. A second turn (new send,
     * continuation, or another tab) is released back to the queue and retried
     * until retryUntil(); a real exception trips maxExceptions=1 and fails fast
     * (no re-stream). Lock contention is not an exception, so it does not count.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->conversationId)
                ->releaseAfter(5)
                ->expireAfter(150),
        ];
    }

    public function handle(CreditService $creditService): void
    {
        $this->team->refresh();

        if (resolve(HostedWorkspaceAccess::class)->isPaused($this->team)) {
            $creditService->refundReservation(
                $this->team,
                resolutionKey: $this->resolutionKey(),
                conversationId: $this->conversationId,
            );
            $this->broadcastSafely(new ChatStreamFailed(
                conversationId: $this->conversationId,
                message: __('billing.access.paused_chat'),
            ));

            return;
        }

        $this->bindAuth();

        ChatTelemetry::tagCurrentScope(
            $this->conversationId,
            (string) $this->team->getKey(),
            $this->resolved['model'] ?? 'unknown',
        );
        ChatTelemetry::breadcrumb('job.started', ['message_length' => strlen($this->message)]);

        $pendingActions = resolve(PendingActionService::class);
        $superseded = $pendingActions->supersedePendingForConversation($this->conversationId);

        if ($superseded !== []) {
            ChatTelemetry::breadcrumb('pending_actions.superseded', [
                'count' => count($superseded),
            ]);
            $this->broadcastSafely(new PendingActionsSuperseded(
                conversationId: $this->conversationId,
                pendingActionIds: array_map(
                    static fn (PendingAction $action): string => (string) $action->getKey(),
                    $superseded,
                ),
            ));
        }

        try {
            $agent = resolve(CrmAssistant::class);
            $agent->withConversationId($this->conversationId);
            $agent->continue($this->conversationId, as: $this->user);
            $agent->withUserTimezone($this->user->timezone);
            $agent->withMentions($this->mentions);
            $agent->withSupersededProposals($this->summarizeSuperseded($superseded));
            $agent->withResolvedActions(
                $pendingActions->resolvedSinceLastAssistantMessage($this->conversationId),
            );

            $channel = new PrivateChannel("chat.conversation.{$this->conversationId}");
            $broadcaster = new StreamEventBroadcaster($channel);
        } catch (Throwable $e) {
            $creditService->refundReservation(
                $this->team,
                resolutionKey: $this->resolutionKey(),
                conversationId: $this->conversationId,
            );
            ChatTelemetry::breadcrumb('stream.pre_model_failed', ['exception' => $e->getMessage()]);
            $this->broadcastSafely(new ChatStreamFailed(
                conversationId: $this->conversationId,
                message: 'The assistant could not start. Please try again.',
            ));
            $this->releaseAuth();

            return;
        }

        if (! ProviderRateGate::tryAcquire($this->resolved['provider'])) {
            ChatTelemetry::breadcrumb('stream.provider_gate_release', ['attempt' => $this->attempts()]);
            $this->releaseAuth();
            $this->release(random_int(1, 4));

            return;
        }

        try {
            $response = $agent->stream(
                prompt: $this->message,
                provider: $this->resolved['provider'],
                model: $this->resolved['model'],
            );

            $cancelled = false;
            $cacheKey = "chat:cancel:{$this->conversationId}";

            $response->each(function (StreamEvent $event) use ($broadcaster, $cacheKey, &$cancelled): void {
                if ($event instanceof Error) {
                    throw ProviderStreamError::toException($event);
                }

                if (! $cancelled && Cache::pull($cacheKey) !== null) {
                    $cancelled = true;

                    return;
                }

                if ($cancelled) {
                    return;
                }

                $broadcaster->broadcast($event);
            });

            if ($cancelled) {
                $creditService->settleReservedMinimum(
                    team: $this->team,
                    user: $this->user,
                    conversationId: $this->conversationId,
                    resolutionKey: $this->resolutionKey(),
                    reason: 'cancelled',
                );
                ChatTelemetry::breadcrumb('stream.cancelled', []);
                $this->broadcastSafely(new ChatStreamFailed(
                    conversationId: $this->conversationId,
                    message: 'Generation stopped.',
                ));

                return;
            }

            $response->then(function (StreamedAgentResponse $streamedResponse) use ($creditService): void {
                ChatTelemetry::breadcrumb('stream.completed', [
                    'input_tokens' => $streamedResponse->usage->promptTokens,
                    'output_tokens' => $streamedResponse->usage->completionTokens,
                ]);

                $this->broadcastSafely(new ConversationResolved(
                    userId: (string) $this->user->getKey(),
                    conversationId: $streamedResponse->conversationId,
                ));

                $creditService->settleReservation(
                    team: $this->team,
                    user: $this->user,
                    type: AiCreditType::Chat,
                    model: $streamedResponse->meta->model ?? 'unknown',
                    inputTokens: $streamedResponse->usage->promptTokens,
                    outputTokens: $streamedResponse->usage->completionTokens,
                    toolCallsCount: $streamedResponse->toolCalls->count(),
                    conversationId: $streamedResponse->conversationId,
                    resolutionKey: $this->resolutionKey(),
                );

                $this->persistMentions();
                $this->persistUserDocument();
                $this->materializeAssistantDocument($streamedResponse);
                $this->broadcastFollowUps($streamedResponse);
            });
        } catch (Throwable $e) {
            // Rate-limit / overloaded errors are transient -> release with backoff.
            // release() does not count against MaxExceptions(1); attempts() increments
            // each retry. Bounded by this cap AND the job's retryUntil() (now+3min).
            // Anything else rethrows and fails fast, exactly as before.
            if ($this->isRateLimited($e) && $this->attempts() < self::MAX_RATE_LIMIT_RETRIES) {
                ChatTelemetry::breadcrumb('stream.rate_limited_retry', ['attempt' => $this->attempts()]);
                // Honor the provider's Retry-After when present; jitter spreads
                // the re-dispatch so concurrent 429ed jobs don't stampede back.
                $delay = $this->retryDelaySeconds($this->attempts(), $e) + random_int(0, 3);
                $this->broadcastSafely(new ChatStreamRetrying(
                    conversationId: $this->conversationId,
                    attempt: $this->attempts() + 1,
                    maxAttempts: self::MAX_RATE_LIMIT_RETRIES,
                    delaySeconds: $delay,
                ));
                $this->release($delay);

                return;
            }

            throw $e;
        } finally {
            $this->releaseAuth();
        }
    }

    public function retryDelaySeconds(int $attempts, ?Throwable $e = null): int
    {
        $base = (int) min(2 ** $attempts, 30);

        $retryAfter = $e instanceof RequestException
            ? (int) ($e->response->header('Retry-After') ?: 0)
            : 0;

        return max($base, min($retryAfter, 60));
    }

    /**
     * The provider surfaces a 429 as a typed RateLimitedException on its wrapped
     * (non-streaming) path, but as a raw HTTP-client RequestException on the
     * streaming path. Treat both — plus overloaded (529/503) — as retryable.
     */
    public function isRateLimited(?Throwable $e): bool
    {
        if ($e instanceof RateLimitedException || $e instanceof ProviderOverloadedException) {
            return true;
        }

        return $e instanceof RequestException
            && in_array($e->response->status(), [429, 529, 503], true);
    }

    public function failed(?Throwable $exception): void
    {
        resolve(CreditService::class)->settleReservedMinimum(
            team: $this->team,
            user: $this->user,
            conversationId: $this->conversationId,
            resolutionKey: $this->resolutionKey(),
            reason: 'job_failed',
        );

        ChatTelemetry::breadcrumb('job.failed', [
            'exception' => $exception?->getMessage(),
            'class' => $exception instanceof Throwable ? $exception::class : null,
        ]);

        resolve(PendingActionService::class)->supersedePendingForConversation($this->conversationId);

        try {
            $this->persistFailedTurn($exception);
        } catch (Throwable $e) {
            ChatTelemetry::breadcrumb('failed.persist_failed', ['exception' => $e->getMessage()]);
        }

        $this->broadcastSafely(new ChatStreamFailed(
            conversationId: $this->conversationId,
            message: $this->failureMessage($exception),
        ));
    }

    private function failureMessage(?Throwable $exception): string
    {
        if ($exception instanceof TimeoutExceededException) {
            return __("This model didn't respond within the time limit (:seconds s). Try a shorter prompt, or switch to a faster model.", [
                'seconds' => self::TIMEOUT_SECONDS,
            ]);
        }

        if ($this->isRateLimited($exception)) {
            return __('The assistant is being rate-limited. Please try again in a moment — anything you already approved was saved.');
        }

        return __('The assistant encountered an error. Please try again.');
    }

    /**
     * Make a dead turn coherent: ensure the user's message is persisted (the
     * ConversationStore flushes rows only on stream success, so a mid-stream
     * death loses them) and append a visible assistant failure note that
     * survives reload.
     *
     * The ConversationStore writes the user row then the assistant row,
     * back-to-back, only once the stream fully succeeds. `handle()`'s
     * post-stream `then()` callback (settleReservation / persistMentions /
     * persistUserDocument / materializeAssistantDocument / broadcastFollowUps)
     * then runs synchronously and un-guarded — if any of those steps throws,
     * the job still fails even though both real rows already exist. Inspecting
     * only the single latest row can't tell that case apart from "the stream
     * died before the store wrote anything": the latest row would be the
     * assistant reply, not the user message, so the old guard concluded the
     * user message was never persisted and inserted a duplicate plus a false
     * error note on a turn that actually succeeded. Looking at the last TWO
     * rows lets us tell a truly complete turn (user then assistant, matching
     * this message) apart from a genuinely dead one.
     *
     * Residual: if the user sends the IDENTICAL message in two consecutive
     * turns and the first succeeds while the second later fails, the last-two
     * check sees {user: this message, assistant: first reply} and treats the
     * second turn as already complete, so it won't be backfilled. The same
     * ambiguity applies when the prior turn was itself a failed+backfilled
     * turn for that identical message: its `[user, assistant-note]` pair is
     * indistinguishable from a completed one, so a second failure in a row
     * is likewise skipped. Both degrade to the pre-existing "message lost"
     * behavior for that one edge case — never a duplicate or a false error
     * note — and are accepted rather than solved with more machinery.
     */
    private function persistFailedTurn(?Throwable $exception): void
    {
        $now = now();
        $table = DB::table('agent_conversation_messages');

        $lastTwo = $table->clone()
            ->where('conversation_id', $this->conversationId)->latest()
            ->orderByDesc('id')
            ->limit(2)
            ->get(['role', 'content']);

        $last = $lastTwo->get(0);
        $prev = $lastTwo->get(1);

        $turnAlreadyComplete = $last !== null
            && $last->role === 'assistant'
            && $prev !== null
            && $prev->role === 'user'
            && $prev->content === $this->message;

        if ($turnAlreadyComplete) {
            return;
        }

        $storePersistedUser = $last !== null
            && $last->role === 'user'
            && $last->content === $this->message;

        if (! $storePersistedUser) {
            $table->insert([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $this->conversationId,
                'user_id' => (string) $this->user->getKey(),
                'agent' => CrmAssistant::class,
                'role' => 'user',
                'content' => $this->message,
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => '[]',
                'document' => json_encode($this->document, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $text = $this->failureMessage($exception);
        $document = $this->getParser()->buildFromText($text, [], $this->team);

        $table->insert([
            'id' => (string) Str::uuid7(),
            'conversation_id' => $this->conversationId,
            'user_id' => (string) $this->user->getKey(),
            'agent' => CrmAssistant::class,
            'role' => 'assistant',
            'content' => $text,
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => json_encode(['error' => true], JSON_THROW_ON_ERROR),
            'document' => json_encode($document, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  list<PendingAction>  $superseded
     * @return list<array{operation: string, entity_type: string, label: string|null}>
     */
    private function summarizeSuperseded(array $superseded): array
    {
        return array_map(static function (PendingAction $action): array {
            $data = $action->action_data;
            $display = $action->display_data;

            $label = null;
            foreach (['name', 'title'] as $field) {
                if (isset($display[$field]) && is_string($display[$field]) && $display[$field] !== '') {
                    $label = $display[$field];
                    break;
                }
                if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                    $label = $data[$field];
                    break;
                }
            }

            return [
                'operation' => $action->operation->value,
                'entity_type' => $action->entity_type,
                'label' => $label,
            ];
        }, $superseded);
    }

    private function persistMentions(): void
    {
        if ($this->mentions === []) {
            return;
        }

        $userMessageId = DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->where('role', 'user')
            ->latest('created_at')
            ->value('id');

        if ($userMessageId === null) {
            return;
        }

        $rows = array_map(static fn (array $m): array => [
            'id' => (string) Str::ulid(),
            'message_id' => $userMessageId,
            'type' => $m['type'],
            'record_id' => $m['id'],
            'label' => $m['label'],
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->mentions);

        DB::table('agent_conversation_message_mentions')->insert($rows);
    }

    /**
     * Update the latest user message row with the editor's document JSON.
     *
     * Runs in the post-stream `then()` callback after the agent's ConversationStore
     * has inserted the user message row. If this UPDATE fails (DB blip), the row
     * keeps its column DEFAULT of `{"type":"doc","content":[]}` — the user message
     * is still readable, just without mention-chip rendering.
     */
    private function persistUserDocument(): void
    {
        $latestId = DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->where('role', 'user')
            ->latest()
            ->orderByDesc('id')
            ->value('id');

        if ($latestId === null) {
            return;
        }

        DB::table('agent_conversation_messages')
            ->where('id', $latestId)
            ->update(['document' => json_encode($this->document, JSON_THROW_ON_ERROR)]);
    }

    /**
     * Materialize the assistant's response as a TipTap document on the
     * latest assistant message row. Runs after the agent's ConversationStore
     * has persisted the assistant message with its plain text `content`.
     *
     * v1 emits no mention chips in assistant prose — future work can extract
     * structured entity references from tool results.
     */
    private function materializeAssistantDocument(StreamedAgentResponse $streamedResponse): void
    {
        // Collapse here too so the `document` column owns its own correctness — the
        // store fixes `content`, but the document is built independently. Idempotent
        // if the store already collapsed the shared response instance.
        $assistantContent = AssistantText::collapseRepeated($streamedResponse->text);

        if ($assistantContent === '') {
            return;
        }

        $document = $this->getParser()->buildFromText($assistantContent, [], $this->team);

        $latestId = DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->where('role', 'assistant')
            ->latest()
            ->orderByDesc('id')
            ->value('id');

        if ($latestId === null) {
            return;
        }

        DB::table('agent_conversation_messages')
            ->where('id', $latestId)
            ->update(['document' => json_encode($document, JSON_THROW_ON_ERROR)]);
    }

    private function getParser(): TipTapDocumentParser
    {
        return resolve(TipTapDocumentParser::class);
    }

    private function broadcastFollowUps(StreamedAgentResponse $streamedResponse): void
    {
        $conversationId = $streamedResponse->conversationId;
        if ($conversationId === null) {
            return;
        }

        $toolCalls = $streamedResponse->toolResults
            ->map(static fn (ToolResult $toolResult): array => [
                'name' => $toolResult->name,
                'result' => $toolResult->result,
            ])
            ->all();

        $chips = resolve(FollowUpService::class)->suggest($toolCalls);

        if ($chips === []) {
            return;
        }

        $this->broadcastSafely(new FollowUpsSuggested(
            conversationId: $conversationId,
            chips: $chips,
        ));
    }

    private function broadcastSafely(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            ChatTelemetry::breadcrumb('broadcast.dropped', ['event' => $event::class, 'reason' => $e->getMessage()]);
        }
    }

    private function bindAuth(): void
    {
        Auth::guard('web')->setUser($this->user);
    }

    private function releaseAuth(): void
    {
        Auth::guard('web')->forgetUser();
    }

    private function resolutionKey(): string
    {
        return 'resolve-'.$this->turnId;
    }
}
