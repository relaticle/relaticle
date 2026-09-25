<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Features\SetupConversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Support\TurnPresence;

/**
 * Opens the setup conversation by having the assistant speak first.
 *
 * The thread is seeded empty and this runs when its owner opens it, so the
 * greeting streams in while they watch instead of arriving as text that was
 * already there. It is a real turn on a real model: what it says comes from
 * the <onboarding> block, not from a template.
 */
final readonly class StartSetupGreeting
{
    /**
     * Long enough to cover the turn's own lifetime (ProcessChatMessage times
     * out at 120 seconds and stops retrying at 3 minutes), short enough that a
     * turn which failed before writing anything is retried the next time the
     * owner opens the thread.
     */
    private const int LOCK_TTL_SECONDS = 180;

    public function __construct(
        private CreditService $credits,
        private AiModelResolver $models,
    ) {}

    public function execute(User $user, string $conversationId): bool
    {
        if (! Feature::active(SetupConversation::class)) {
            return false;
        }

        $workspace = $user->currentWorkspace;

        if (! $workspace instanceof Workspace) {
            return false;
        }

        $conversation = $workspace->setupConversation;

        if (! $conversation instanceof AgentConversation
            || $conversation->id !== $conversationId
            || $conversation->participant_id !== (string) $user->getKey()) {
            return false;
        }

        if ($conversation->messages()->exists()) {
            return false;
        }

        // Atomic, so two tabs opening the thread at once still greet once.
        // WithoutOverlapping would serialize a duplicate job, not drop it.
        if (! Cache::add($this->lockKey($conversationId), true, self::LOCK_TTL_SECONDS)) {
            return false;
        }

        $turnId = (string) Str::ulid();

        if (! $this->credits->reserveCredit(
            $workspace,
            reservationKey: "reserve-{$turnId}",
            conversationId: $conversationId,
            userId: (string) $user->getKey(),
        )) {
            Cache::forget($this->lockKey($conversationId));

            return false;
        }

        TurnPresence::begin($conversationId, turnId: $turnId, message: '', origin: MessageOrigin::Greeting);

        dispatch(new ProcessChatMessage(
            user: $user,
            workspace: $workspace,
            message: '',
            conversationId: $conversationId,
            resolved: $this->models->resolve($user),
            turnId: $turnId,
            origin: MessageOrigin::Greeting,
        ));

        return true;
    }

    private function lockKey(string $conversationId): string
    {
        return "chat:setup-greeting:{$conversationId}";
    }
}
