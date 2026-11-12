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
    private const int CLAIM_DAYS = 7;

    public function __construct(
        private CreditService $credits,
        private AiModelResolver $models,
    ) {}

    public function execute(Workspace $workspace, string $sourceKey): bool
    {
        if (! Feature::active(SetupConversation::class) || ! $workspace->isPersonalWorkspace() || ! $workspace->isInSetupWindow()) {
            return false;
        }

        $owner = $workspace->owner;
        $conversation = $workspace->setupConversation;

        if (! $owner instanceof User
            || ! $conversation instanceof AgentConversation
            || $conversation->participant_id !== (string) $owner->getKey()) {
            return false;
        }

        if (! $conversation->messages()->exists()) {
            return false;
        }

        $claimKey = "chat:data-arrived:{$conversation->id}:{$sourceKey}";

        if (! Cache::add($claimKey, true, now()->addDays(self::CLAIM_DAYS))) {
            return false;
        }

        $turnId = (string) Str::ulid();

        if (! $this->credits->reserveCredit(
            $workspace,
            reservationKey: "reserve-{$turnId}",
            conversationId: $conversation->id,
            userId: (string) $owner->getKey(),
        )) {
            Cache::forget($claimKey);

            return false;
        }

        if (TurnPresence::current($conversation->id) === null) {
            TurnPresence::begin($conversation->id, turnId: $turnId, message: '', origin: MessageOrigin::DataArrived);
        }

        $owner->setRelation('currentWorkspace', $workspace);

        dispatch(new ProcessChatMessage(
            user: $owner,
            workspace: $workspace,
            message: '',
            conversationId: $conversation->id,
            resolved: $this->models->resolve($owner),
            turnId: $turnId,
            origin: MessageOrigin::DataArrived,
            claimKey: $claimKey,
        ));

        return true;
    }
}
