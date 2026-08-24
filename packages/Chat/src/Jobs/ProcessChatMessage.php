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
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Exceptions\StreamErrorException;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Events\ChatStreamRetrying;
use Relaticle\Chat\Events\ConversationResolved;
use Relaticle\Chat\Events\FollowUpsSuggested;
use Relaticle\Chat\Events\PendingActionsSuperseded;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\FollowUpService;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\TipTapDocumentParser;
use Relaticle\Chat\Services\TurnContinuationService;
use Relaticle\Chat\Support\AssistantText;
use Relaticle\Chat\Support\ChatTelemetry;
use Relaticle\Chat\Support\ConversationTitleGate;
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

    private const int CONTEXT_LEDGER_CAP = 10;

    /**
     * @param  array{provider: string|null, model: string|null, id: string|null, source: string}  $resolved
     * @param  list<array{type: string, id: string, label: string}>  $mentions
     * @param  array<string, mixed>  $document
     * @param  array{type: string, id: string, label: string}|null  $pageContext
     */
    public function __construct(
        private readonly User $user,
        private readonly Team $team,
        public readonly string $message,
        public readonly string $conversationId,
        private readonly array $resolved,
        public readonly array $mentions = [],
        public readonly array $document = ['type' => 'doc', 'content' => []],
        public readonly ?array $pageContext = null,
        public readonly string $turnId = '',
        public readonly int $failoverDepth = 0,
        public readonly bool $isContinuation = false,
        public readonly ?string $resumesTurnId = null,
    ) {
        $this->onConnection('redis-chat');
        $this->onQueue('chat');
        $this->afterCommit = true;
    }

    private string $textAfterLastToolCall = '';

    private bool $sawToolCall = false;

    /**
     * Whether any stream event has actually reached the broadcaster. Failover
     * to the next auto-chain model is only safe before this turns true: once
     * the client has seen partial output, a fresh model would either repeat
     * or contradict it.
     */
    private bool $streamedAnything = false;

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
        $startedAt = microtime(true);

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

        // A continuation is dispatched the moment the conversation runs out of
        // pending proposals, and during a chained turn that is briefly true
        // between two steps streaming in. WithoutOverlapping only delays this
        // job until the stream ends, so the check has to run again here, where
        // the later steps already exist: continuing now would supersede them.
        if ($this->isContinuation && resolve(TurnContinuationService::class)->hasPendingProposals($this->conversationId)) {
            ChatTelemetry::breadcrumb('continuation.aborted', ['reason' => 'pending_proposals']);

            // Hand the resume back, or deciding the step that blocked it would
            // find the once-per-turn guard already spent and resume nothing.
            if ($this->resumesTurnId !== null) {
                resolve(TurnContinuationService::class)->release($this->resumesTurnId);
            }

            $creditService->refundReservation(
                $this->team,
                resolutionKey: $this->resolutionKey(),
                conversationId: $this->conversationId,
            );
            $this->releaseAuth();

            return;
        }

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
            $agent->withTurnId($this->turnId);
            $agent->continue($this->conversationId, as: $this->user);
            $agent->withUserTimezone($this->user->timezone);
            $agent->withCurrentUser([
                'name' => $this->user->name,
                'id' => (string) $this->user->getKey(),
                'role' => $this->user->ownsTeam($this->team) ? 'owner' : 'member',
            ]);
            $agent->withMentions($this->mentions);
            $agent->withPageContext($this->pageContext);
            $agent->withContextLedger($this->contextLedger());
            $agent->withSupersededProposals(
                $pendingActions->supersededForConversation($this->conversationId),
            );
            $agent->withResolvedActions(
                $pendingActions->resolvedForConversation($this->conversationId),
            );

            $channel = new PrivateChannel("chat.conversation.{$this->conversationId}");
            $broadcaster = new StreamEventBroadcaster($channel);
        } catch (Throwable $e) {
            $creditService->refundReservation(
                $this->team,
                resolutionKey: $this->resolutionKey(),
                conversationId: $this->conversationId,
            );
            // The job returns normally from here, so a breadcrumb alone would never
            // reach Sentry: breadcrumbs only ship attached to a captured event. Without
            // report() a broken deploy fails every turn for every tenant in silence.
            report($e);
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

        // The prompt a resumed turn runs on is ours, not the user's, so the row
        // it lands in is marked and the transcript leaves it out. Resolved
        // through the contract because that is what ChatServiceProvider binds
        // the single shared store instance to; the concrete class would build a
        // second one and this flag would land on the wrong object.
        if ($this->isContinuation) {
            resolve(ConversationStore::class)->nextUserMessageIsContinuation = true;
        }

        try {
            $response = $agent->stream(
                prompt: $this->message,
                provider: $this->resolved['provider'],
                model: $this->resolved['model'],
            );

            $cancelled = false;
            $cacheKey = "chat:cancel:{$this->conversationId}";
            $this->textAfterLastToolCall = '';
            $this->sawToolCall = false;

            $response->each(function (StreamEvent $event) use ($broadcaster, $cacheKey, &$cancelled): void {
                if ($event instanceof Error) {
                    throw ProviderStreamError::toException($event);
                }

                if ($event instanceof ToolCall) {
                    $this->sawToolCall = true;
                    $this->textAfterLastToolCall = '';
                } elseif ($event instanceof TextDelta) {
                    $this->textAfterLastToolCall .= $event->delta;
                }

                if (! $cancelled && Cache::pull($cacheKey) !== null) {
                    $cancelled = true;

                    return;
                }

                if ($cancelled) {
                    return;
                }

                $this->streamedAnything = true;
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

            $response->then(function (StreamedAgentResponse $streamedResponse) use ($creditService, $startedAt): void {
                // promptTokens is the UNCACHED remainder only, so it understates the
                // real prompt on a cached turn. Record the cache legs next to it;
                // credits are still priced on model + tool calls, not tokens.
                ChatTelemetry::breadcrumb('stream.completed', [
                    'input_tokens' => $streamedResponse->usage->promptTokens,
                    'output_tokens' => $streamedResponse->usage->completionTokens,
                    'cache_read_tokens' => $streamedResponse->usage->cacheReadInputTokens,
                    'cache_write_tokens' => $streamedResponse->usage->cacheWriteInputTokens,
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
                $this->materializeAssistantDocument($streamedResponse, $startedAt);
                $this->broadcastFollowUps($streamedResponse);
                $this->maybeTitleFromTurn($streamedResponse);
            });
        } catch (Throwable $e) {
            // Rate-limit, overloaded, dropped-connection and provider stream errors are
            // transient -> release with backoff.
            // release() does not count against MaxExceptions(1); attempts() increments
            // each retry. Bounded by this cap AND the job's retryUntil() (now+3min).
            // Anything else rethrows and fails fast, exactly as before.
            if ($this->isTransient($e) && $this->attempts() < self::MAX_RATE_LIMIT_RETRIES) {
                ChatTelemetry::breadcrumb('stream.transient_retry', ['attempt' => $this->attempts(), 'exception' => $e::class]);
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

            // The user's model choice was 'auto' and nothing has streamed yet: fail
            // over to the next plan-allowed chain entry instead of failing the turn.
            // An explicit pick never lands here (source stays 'explicit'), so a user
            // who chose a model deliberately always sees today's error, never a
            // silent swap to a different (differently priced) model. Bounded to one
            // hop by failoverDepth so a chain of bad providers still terminates.
            // The credit reservation is untouched here, exactly like the transient
            // retry above: it stays open under the same resolutionKey (keyed on
            // turnId, which the re-dispatched job keeps), and whichever attempt
            // finally finishes the turn settles it exactly once.
            if ($this->resolved['source'] === 'auto'
                && $this->failoverDepth === 0
                && ! $this->streamedAnything) {
                $next = resolve(AiModelResolver::class)->failoverNext($this->user, (string) ($this->resolved['id'] ?? ''));

                if ($next !== null) {
                    ChatTelemetry::breadcrumb('stream.failover', [
                        'from' => $this->resolved['id'] ?? null,
                        'to' => $next['id'],
                        'exception' => $e::class,
                    ]);
                    // Same signal the transient path sends, for the same reason: the
                    // turn is still alive but nothing will stream for a moment, and a
                    // silent gap here lets the client's watchdog call the turn dead.
                    // Which model takes over stays unsaid - the user never picked one.
                    $this->broadcastSafely(new ChatStreamRetrying(
                        conversationId: $this->conversationId,
                        attempt: $this->attempts() + 1,
                        maxAttempts: self::MAX_RATE_LIMIT_RETRIES,
                        delaySeconds: 0,
                    ));
                    dispatch(new self(
                        user: $this->user,
                        team: $this->team,
                        message: $this->message,
                        conversationId: $this->conversationId,
                        resolved: $next,
                        mentions: $this->mentions,
                        document: $this->document,
                        pageContext: $this->pageContext,
                        turnId: $this->turnId,
                        failoverDepth: $this->failoverDepth + 1,
                        isContinuation: $this->isContinuation,
                        resumesTurnId: $this->resumesTurnId,
                    ));

                    return;
                }
            }

            throw $e;
        } finally {
            $this->releaseAuth();

            // storeUserMessage() consumes this on the happy path, but it only
            // runs if the stream reaches its then() callback. A turn that dies
            // first (provider error, release, cancel, failover re-dispatch)
            // would otherwise leave the flag set on the container singleton,
            // which queue workers do not rebuild between jobs: the next job on
            // this worker, any user and any tenant, would have its own question
            // stored as ours and filtered out of its transcript for good.
            resolve(ConversationStore::class)->nextUserMessageIsContinuation = false;
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
     * Every provider failure worth releasing the job for rather than failing the turn.
     *
     * Wider than isRateLimited(), which stays scoped to real throttling because it
     * also picks the user-facing failure copy. A dropped connection and a provider
     * error reported inside the stream body are equally transient, but telling the
     * user they were rate-limited would be a lie.
     */
    public function isTransient(?Throwable $e): bool
    {
        if ($e instanceof ProviderConnectionException) {
            return true;
        }

        if ($e instanceof StreamErrorException) {
            return ProviderStreamError::isRetryable($e->error);
        }

        return $this->isRateLimited($e);
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
        // A turn that never streamed never reached a provider, so it cost nothing and
        // must not be charged. The commonest case is a queue backlog: retryUntil() is
        // stamped at dispatch, so the worker can fail the job at pickup before
        // handle() is ever entered, and this instance is the freshly unserialized one
        // whose $streamedAnything is still false.
        $credits = resolve(CreditService::class);

        if ($this->streamedAnything) {
            $credits->settleReservedMinimum(
                team: $this->team,
                user: $this->user,
                conversationId: $this->conversationId,
                resolutionKey: $this->resolutionKey(),
                reason: 'job_failed',
            );
        } else {
            $credits->refundReservation(
                $this->team,
                resolutionKey: $this->resolutionKey(),
                conversationId: $this->conversationId,
            );
        }

        ChatTelemetry::breadcrumb('job.failed', [
            'exception' => $exception?->getMessage(),
            'class' => $exception instanceof Throwable ? $exception::class : null,
        ]);

        resolve(PendingActionService::class)->supersedePendingForConversation($this->conversationId);

        try {
            $this->persistFailedTurn($exception);
        } catch (Throwable $e) {
            report($e);
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
                'participant_type' => $this->user->getMorphClass(),
                'participant_id' => (string) $this->user->getKey(),
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
            'participant_type' => $this->user->getMorphClass(),
            'participant_id' => (string) $this->user->getKey(),
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
     * Distinct records referenced earlier in this conversation, most recent first.
     *
     * Unlike a typed @mention, a page-context record's name never enters the message
     * text — so once it falls off this ledger the agent loses it entirely (no id, no
     * name, nothing left to fall back on). The conversation's OLDEST page_context row
     * is therefore exempt from the recency cap: it reserves the ledger's last slot
     * instead of being evicted by more recent records, so the total handed to the
     * agent stays bounded at the same cap regardless of how many distinct records
     * the conversation has touched.
     *
     * @return list<array{type: string, id: string, label: string}>
     */
    private function contextLedger(): array
    {
        $rows = DB::table('agent_conversation_message_mentions as mm')
            ->join('agent_conversation_messages as m', 'm.id', '=', 'mm.message_id')
            ->where('m.conversation_id', $this->conversationId)
            ->latest('mm.created_at')
            ->get(['mm.type', 'mm.record_id', 'mm.label', 'mm.source', 'mm.created_at']);

        $anchor = $rows
            ->filter(static fn (object $row): bool => (string) $row->source === 'page_context')
            ->sortBy('created_at')
            ->first();

        $anchorKey = $anchor !== null ? $anchor->type.':'.$anchor->record_id : null;

        $seen = [];
        $ledger = [];

        foreach ($rows as $row) {
            $key = $row->type.':'.$row->record_id;

            if (isset($seen[$key])) {
                continue;
            }

            if (count($ledger) >= self::CONTEXT_LEDGER_CAP) {
                break;
            }

            // One slot before the cap: stop unless this row IS the anchor, so the
            // final slot stays reserved for it (appended below) rather than being
            // taken by whatever is merely next in recency.
            if (count($ledger) === self::CONTEXT_LEDGER_CAP - 1
                && $anchorKey !== null
                && $key !== $anchorKey
                && ! isset($seen[$anchorKey])
            ) {
                break;
            }

            $seen[$key] = true;
            $ledger[] = [
                'type' => (string) $row->type,
                'id' => (string) $row->record_id,
                'label' => (string) $row->label,
            ];
        }

        if ($anchor !== null && $anchorKey !== null && ! isset($seen[$anchorKey])) {
            $ledger[] = [
                'type' => (string) $anchor->type,
                'id' => (string) $anchor->record_id,
                'label' => (string) $anchor->label,
            ];
        }

        return $ledger;
    }

    private function latestMessageId(string $role): ?string
    {
        $id = DB::table('agent_conversation_messages')
            ->where('conversation_id', $this->conversationId)
            ->where('role', $role)
            ->latest()
            ->orderByDesc('id')
            ->value('id');

        return is_string($id) ? $id : null;
    }

    private function persistMentions(): void
    {
        if ($this->mentions === [] && $this->pageContext === null) {
            return;
        }

        $userMessageId = $this->latestMessageId('user');

        if ($userMessageId === null) {
            return;
        }

        $rows = array_map(static fn (array $m): array => [
            'id' => (string) Str::ulid(),
            'message_id' => $userMessageId,
            'type' => $m['type'],
            'record_id' => $m['id'],
            'label' => $m['label'],
            'source' => 'mention',
            'created_at' => now(),
            'updated_at' => now(),
        ], $this->mentions);

        if ($this->pageContext !== null) {
            $rows[] = [
                'id' => (string) Str::ulid(),
                'message_id' => $userMessageId,
                'type' => $this->pageContext['type'],
                'record_id' => $this->pageContext['id'],
                'label' => $this->pageContext['label'],
                'source' => 'page_context',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

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
        $latestId = $this->latestMessageId('user');

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
    private function materializeAssistantDocument(StreamedAgentResponse $streamedResponse, float $startedAt): void
    {
        // The store persisted the full concatenated text; the row is rewritten here
        // with the reply the user should keep (see AssistantText::finalReply), and
        // the document is built from that same text so both columns agree.
        $assistantContent = AssistantText::finalReply($streamedResponse->text, $this->textAfterLastToolCall, $this->sawToolCall);

        if ($assistantContent === '') {
            return;
        }

        $document = $this->getParser()->buildFromText($assistantContent, [], $this->team);

        $latestId = $this->latestMessageId('assistant');

        if ($latestId === null) {
            return;
        }

        $existingMeta = json_decode((string) DB::table('agent_conversation_messages')->where('id', $latestId)->value('meta'), associative: true);
        $meta = is_array($existingMeta) ? $existingMeta : [];
        $meta['duration_ms'] = (int) round((microtime(true) - $startedAt) * 1000);

        DB::table('agent_conversation_messages')
            ->where('id', $latestId)
            ->update([
                'content' => $assistantContent,
                'document' => json_encode($document, JSON_THROW_ON_ERROR),
                'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            ]);
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

    /**
     * Last chance to name a conversation the opening dispatch could not.
     *
     * ChatController fires a titling attempt as the message arrives, off the
     * message alone. When that message had no subject to name, "hey", "do it",
     * "what about the other one?", the titler declines and the chat keeps
     * sitting under its own opening words. By the time the turn ends there IS
     * something to name it from: the assistant just answered, and its reply
     * names the records the turn was actually about.
     *
     * Runs only while ConversationTitleGate still returns a provisional, so a
     * conversation the first attempt named (the usual case) never reaches the
     * model a second time, and a chat the user renamed is never touched. The two
     * dispatches can overlap on a fast turn; both write through the same
     * compare-and-swap, so one of them applies and the other stops.
     *
     * The message comes from the gate, not from $this->message: a turn resumed
     * by a proposal decision runs on a synthetic prompt, and naming a chat after
     * that would be naming it after machinery the user never saw.
     */
    private function maybeTitleFromTurn(StreamedAgentResponse $streamedResponse): void
    {
        $attempt = ConversationTitleGate::afterTurn($this->conversationId);

        if ($attempt === null) {
            return;
        }

        $reply = AssistantText::finalReply($streamedResponse->text, $this->textAfterLastToolCall, $this->sawToolCall);

        if (trim($reply) === '') {
            return;
        }

        dispatch(new GenerateConversationTitle(
            conversationId: $this->conversationId,
            provisionalTitle: $attempt['provisional'],
            message: $attempt['latest'],
            provider: $this->resolved['provider'],
            pageContext: $this->pageContext,
            reply: $reply,
        ));
    }

    private function broadcastSafely(object $event): void
    {
        try {
            broadcast($event);
        } catch (Throwable $e) {
            // A Reverb outage drops every stream event and is otherwise invisible.
            report($e);
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
