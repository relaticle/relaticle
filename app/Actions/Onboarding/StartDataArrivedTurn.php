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

final readonly class StartDataArrivedTurn
{
    private const int DEDUPE_DAYS = 7;

    public function __construct(
        private CreditService $credits,
        private AiModelResolver $models,
    ) {}

    public function execute(Workspace $workspace, string $sourceKey): bool
    {
        if (! Feature::active(SetupConversation::class) || ! $workspace->isPersonalWorkspace()) {
            return false;
        }

        $owner = $workspace->owner;
        $conversation = $workspace->setupConversation;

        if (! $owner instanceof User
            || ! $conversation instanceof AgentConversation
            || $conversation->participant_id !== (string) $owner->getKey()) {
            return false;
        }

        if (! $conversation->messages()->exists() || TurnPresence::current($conversation->id) !== null) {
            return false;
        }

        $dedupeKey = "chat:data-arrived:{$conversation->id}:{$sourceKey}";

        if (! Cache::add($dedupeKey, true, now()->addDays(self::DEDUPE_DAYS))) {
            return false;
        }

        $turnId = (string) Str::ulid();

        if (! $this->credits->reserveCredit(
            $workspace,
            reservationKey: "reserve-{$turnId}",
            conversationId: $conversation->id,
            userId: (string) $owner->getKey(),
        )) {
            Cache::forget($dedupeKey);

            return false;
        }

        TurnPresence::begin($conversation->id, turnId: $turnId, message: '', origin: MessageOrigin::DataArrived);

        dispatch(new ProcessChatMessage(
            user: $owner,
            workspace: $workspace,
            message: '',
            conversationId: $conversation->id,
            resolved: $this->models->resolve($owner),
            turnId: $turnId,
            origin: MessageOrigin::DataArrived,
        ));

        return true;
    }
}
