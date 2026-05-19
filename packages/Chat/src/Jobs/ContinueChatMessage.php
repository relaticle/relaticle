<?php

declare(strict_types=1);

namespace Relaticle\Chat\Jobs;

use App\Models\Team;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
#[Tries(1)]
final class ContinueChatMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly User $user,
        public readonly Team $team,
        public readonly string $conversationId,
        public readonly string $prompt,
    ) {
        $this->onQueue('chat');
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
            $this->releaseAuth();

            return;
        }

        $resolved = $modelResolver->resolve($this->user);

        try {
            $agent = resolve(CrmAssistant::class);
            $agent->withConversationId($this->conversationId);
            $agent->continue($this->conversationId, as: $this->user);

            $channel = new PrivateChannel("chat.conversation.{$this->conversationId}");

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

                Cache::add(
                    "chat:refund-lock:{$this->team->getKey()}:{$this->settlementToken()}",
                    '1',
                    now()->addHour(),
                );

                $creditService->settleReservation(
                    team: $this->team,
                    user: $this->user,
                    type: AiCreditType::Chat,
                    model: $streamedResponse->meta->model ?? 'unknown',
                    inputTokens: $streamedResponse->usage->promptTokens,
                    outputTokens: $streamedResponse->usage->completionTokens,
                    toolCallsCount: $streamedResponse->toolCalls->count(),
                    conversationId: $streamedResponse->conversationId,
                    idempotencyKey: $streamedResponse->invocationId,
                );
            });
        } finally {
            $this->releaseAuth();
        }
    }

    public function failed(?Throwable $exception): void
    {
        resolve(CreditService::class)->refundReservation(
            $this->team,
            idempotencyToken: $this->settlementToken(),
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

    private function settlementToken(): string
    {
        $payload = [
            $this->conversationId,
            (string) $this->user->getKey(),
            (string) $this->team->getKey(),
            $this->prompt,
        ];

        return $this->conversationId.':'.hash('xxh3', implode("\0", $payload));
    }
}
