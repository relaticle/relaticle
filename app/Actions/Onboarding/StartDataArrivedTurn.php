<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Features\SetupConversation;
use App\Models\User;
use App\Models\Workspace;
use App\Queries\Onboarding\ArrivedRecordsQuery;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
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
    private const int REMEMBERED_HOURS = 24;

    public function __construct(
        private CreditService $credits,
        private AiModelResolver $models,
        private ArrivedRecordsQuery $arrived,
    ) {}

    public function execute(Workspace $workspace, ?string $sourceKey = null): bool
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
            $this->rememberWhileGreeting($owner, $workspace, $conversation->id, $sourceKey);

            return false;
        }

        $sourceKey = $this->sourceKeyToAnnounce($conversation->id, $sourceKey);

        if ($sourceKey === null) {
            return false;
        }

        $claimKey = "chat:data-arrived:{$conversation->id}:{$sourceKey}";

        if (! Cache::add($claimKey, true, now()->addDays(Workspace::SETUP_WINDOW_DAYS))) {
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

        $this->dispatchTurn($owner, $workspace, $conversation->id, $turnId, $claimKey);

        return true;
    }

    private function dispatchTurn(User $owner, Workspace $workspace, string $conversationId, string $turnId, string $claimKey): void
    {
        if (TurnPresence::current($conversationId) === null) {
            TurnPresence::begin($conversationId, turnId: $turnId, message: '', origin: MessageOrigin::DataArrived);
        }

        $owner->setRelation('currentWorkspace', $workspace);

        dispatch(new ProcessChatMessage(
            user: $owner,
            workspace: $workspace,
            message: '',
            conversationId: $conversationId,
            resolved: $this->models->resolve($owner),
            turnId: $turnId,
            origin: MessageOrigin::DataArrived,
            claimKey: $claimKey,
        ));
    }

    private function sourceKeyToAnnounce(string $conversationId, ?string $sourceKey): ?string
    {
        if ($sourceKey === null) {
            $remembered = Cache::get($this->rememberedKey($conversationId));

            return is_string($remembered) ? $remembered : null;
        }

        $this->remember($conversationId, $sourceKey);

        return $sourceKey;
    }

    // No stored message and a turn in flight means the first greeting is streaming. Only records
    // that landed after it began are missing from its prompt, so only those wait for its end.
    private function rememberWhileGreeting(User $owner, Workspace $workspace, string $conversationId, ?string $sourceKey): void
    {
        $greeting = TurnPresence::current($conversationId);

        if ($sourceKey === null || $greeting === null) {
            return;
        }

        $began = Date::parse($greeting['started_at'])->startOfSecond();

        if ($this->arrived->anySince($owner, $workspace, $began)) {
            $this->remember($conversationId, $sourceKey);
        }
    }

    private function remember(string $conversationId, string $sourceKey): void
    {
        Cache::put($this->rememberedKey($conversationId), $sourceKey, now()->addHours(self::REMEMBERED_HOURS));
    }

    private function rememberedKey(string $conversationId): string
    {
        return "chat:data-arrived-last:{$conversationId}";
    }
}
