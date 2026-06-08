<?php

declare(strict_types=1);

namespace Relaticle\Chat\Jobs;

use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Events\ChatStreamFailed;
use Relaticle\Chat\Events\ConversationResolved;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Support\ChatTelemetry;
use Throwable;

#[Timeout(120)]
#[MaxExceptions(1)]
final class ContinueChatMessage implements ShouldQueue
{
    use Queueable;

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

        if (! $creditService->reserveCredit($this->team)) {
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
        } finally {
            $this->releaseAuth();
        }
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

        broadcast(new ChatStreamFailed(
            conversationId: $this->conversationId,
            message: 'Could not continue the conversation. Please try again.',
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
