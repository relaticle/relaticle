<?php

declare(strict_types=1);

namespace Relaticle\Chat\Jobs;

use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Events\ChatStreamRetrying;
use Relaticle\Chat\Events\ConversationResolved;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\ChatTelemetry;
use Relaticle\Chat\Support\ProviderStreamError;
use Throwable;

#[Timeout(120)]
#[MaxExceptions(1)]
final class ContinueChatMessage implements ShouldQueue
{
    use Queueable;

    private const int MAX_RATE_LIMIT_RETRIES = 5;

    public function __construct(
        public readonly User $user,
        public readonly Team $team,
        public readonly string $conversationId,
        public readonly string $prompt,
        public readonly string $turnId = '',
    ) {
        $this->onQueue('chat');
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

    public function handle(CreditService $creditService, AiModelResolver $modelResolver): void
    {
        $this->bindAuth();

        ChatTelemetry::tagCurrentScope(
            $this->conversationId,
            (string) $this->team->getKey(),
            'continuation',
        );
        ChatTelemetry::breadcrumb('continuation.started', ['prompt_length' => strlen($this->prompt)]);

        if ($this->attempts() === 1 && ! $creditService->reserveCredit($this->team)) {
            ChatTelemetry::breadcrumb('continuation.credits_exhausted', []);
            broadcast(new ChatStreamFailed(
                conversationId: $this->conversationId,
                message: "You're out of AI credits, so I can't continue here. Add credits to keep going — the change you approved was still saved.",
            ));
            $this->releaseAuth();

            return;
        }

        $resolved = $modelResolver->resolve($this->user);

        try {
            $agent = resolve(CrmAssistant::class);
            $agent->withConversationId($this->conversationId);
            $agent->continue($this->conversationId, as: $this->user);
            $agent->withResolvedActions(
                resolve(PendingActionService::class)
                    ->resolvedSinceLastAssistantMessage($this->conversationId),
            );

            $channel = new PrivateChannel("chat.conversation.{$this->conversationId}");
        } catch (Throwable $e) {
            $creditService->refundReservation(
                $this->team,
                resolutionKey: $this->resolutionKey(),
                conversationId: $this->conversationId,
            );
            ChatTelemetry::breadcrumb('continuation.pre_model_failed', ['exception' => $e->getMessage()]);
            broadcast(new ChatStreamFailed(
                conversationId: $this->conversationId,
                message: 'The assistant could not continue. Please try again.',
            ));
            $this->releaseAuth();

            return;
        }

        try {
            $response = $agent->stream(
                prompt: $this->prompt,
                provider: $resolved['provider'],
                model: $resolved['model'],
            );

            $response->each(function (StreamEvent $event) use ($channel): void {
                if ($event instanceof Error) {
                    throw ProviderStreamError::toException($event);
                }

                $event->broadcastNow($channel);
            });

            $response->then(function (StreamedAgentResponse $streamedResponse) use ($creditService): void {
                broadcast(new ConversationResolved(
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
            });
        } catch (Throwable $e) {
            // Rate-limit / overloaded errors are transient -> release with backoff;
            // anything else rethrows and fails fast.
            if ($this->isRateLimited($e) && $this->attempts() < self::MAX_RATE_LIMIT_RETRIES) {
                ChatTelemetry::breadcrumb('continuation.rate_limited_retry', ['attempt' => $this->attempts()]);
                $delay = $this->retryDelaySeconds($this->attempts());
                broadcast(new ChatStreamRetrying(
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

    public function retryDelaySeconds(int $attempts): int
    {
        return (int) min(2 ** $attempts, 30);
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
            reason: 'continuation_failed',
        );

        ChatTelemetry::breadcrumb('continuation.failed', [
            'exception' => $exception?->getMessage(),
        ]);

        $message = $this->isRateLimited($exception)
            ? 'The assistant is being rate-limited. Please try again in a moment — anything you already approved was saved.'
            : 'Could not continue the conversation. Please try again.';

        broadcast(new ChatStreamFailed(
            conversationId: $this->conversationId,
            message: $message,
        ));
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
