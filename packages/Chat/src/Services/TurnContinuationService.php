<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\MessageOrigin;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\ResolvedActionText;
use Relaticle\Chat\Support\TurnPresence;

/**
 * Resumes the assistant after the user decides a proposal.
 *
 * An approval is not a turn: the card resolves, the records are written, and the
 * assistant stays silent until the user types something. That is why a chained
 * request used to need a literal "next" after every card. This service turns the
 * decision itself into the next turn.
 *
 * Three gates keep it from running away:
 *  1. It fires only from a human resolution, never from a turn ending, so the
 *     loop always needs a person to advance it.
 *  2. It fires only when the conversation has nothing left pending. A plan
 *     resolved step by step therefore continues once, at the end, and a
 *     continuation can never race ahead of a card the user has not decided.
 *  3. It fires once per resolved turn (`Cache::add` is atomic), so two tabs
 *     approving the same plan, or a double click, still produce one turn.
 *
 * The turn costs a credit like any other. When the workspace has none left the
 * continuation is skipped rather than queued: the user can still type.
 */
final readonly class TurnContinuationService
{
    private const int DEDUPE_TTL_SECONDS = 3600;

    public function __construct(
        private CreditService $credits,
        private AiModelResolver $models,
        private PendingActionService $pendingActions,
    ) {}

    /**
     * Continue the conversation after $resolvedTurnId's proposals were decided.
     * Returns whether a turn was actually queued, so the caller can tell the
     * client to show the assistant working instead of leaving the seconds
     * between the approval and the first token looking like nothing happened.
     *
     * $model is the composer's current pick, carried through so the resumed turn
     * runs on the model the user chose rather than silently dropping to auto
     * mid-flow (the pick lives in the browser, not on the user record).
     * AiModelResolver re-checks availability and the plan, so a value that
     * arrived from the client cannot buy a model the workspace may not use.
     */
    public function resume(User $user, string $conversationId, string $resolvedTurnId, ?string $model = null): bool
    {
        $workspace = $user->currentWorkspace;

        if ($workspace === null) {
            return false;
        }

        if (! Cache::add($this->dedupeKey($resolvedTurnId), true, self::DEDUPE_TTL_SECONDS)) {
            return false;
        }

        if ($this->hasPendingProposals($conversationId)) {
            Cache::forget($this->dedupeKey($resolvedTurnId));

            return false;
        }

        $turnId = (string) Str::ulid();
        $message = ResolvedActionText::resumeOpener($this->justDecided($conversationId, $resolvedTurnId));

        if (! $this->credits->reserveCredit(
            $workspace,
            reservationKey: "reserve-{$turnId}",
            conversationId: $conversationId,
            userId: (string) $user->getKey(),
        )) {
            Cache::forget($this->dedupeKey($resolvedTurnId));

            return false;
        }

        TurnPresence::begin($conversationId, turnId: $turnId, message: '', origin: MessageOrigin::Resume);

        dispatch(new ProcessChatMessage(
            user: $user,
            workspace: $workspace,
            message: $message,
            conversationId: $conversationId,
            resolved: $this->models->resolve($user, $model),
            turnId: $turnId,
            origin: MessageOrigin::Resume,
            resumesTurnId: $resolvedTurnId,
        ));

        return true;
    }

    /**
     * Stated only in the <resolved_actions> system block, a rejection was
     * reported as done in three of five production resumes.
     *
     * @return list<array{operation: string, entity_type: string, status: string, label: string|null, record_id: string|null, record_ids: list<string>, records: list<array{id: string, label: string|null, url: string}>, skipped: list<string>, excluded: list<array{record: string|null, fields: list<string>}>, failure: string|null, just_decided: bool}>
     */
    private function justDecided(string $conversationId, string $resolvedTurnId): array
    {
        return array_values(array_filter(
            $this->pendingActions->resolvedForConversation($conversationId, $resolvedTurnId),
            static fn (array $action): bool => $action['just_decided'],
        ));
    }

    /**
     * Give the turn its resume back after a queued continuation aborted.
     *
     * Load-bearing for the approve-mid-stream case: the steps of a chained turn
     * share one turn_id, so approving step 1 before step 2 has streamed in fires
     * a continuation that the job then correctly refuses. Without this the
     * once-per-turn guard is spent, and approving step 2, the moment this
     * feature exists for, would resume nothing, silently, for an hour.
     */
    public function release(string $resolvedTurnId): void
    {
        Cache::forget($this->dedupeKey($resolvedTurnId));
    }

    /**
     * A proposal still awaiting a decision anywhere in the conversation blocks a
     * continuation: the resumed turn supersedes whatever is pending when it
     * starts, so firing early would cancel a card the user never saw decided.
     */
    public function hasPendingProposals(string $conversationId): bool
    {
        return PendingAction::query()
            ->where('conversation_id', $conversationId)
            ->where('status', PendingActionStatus::Pending)
            ->where('expires_at', '>', now())
            ->exists();
    }

    private function dedupeKey(string $resolvedTurnId): string
    {
        return "chat:continued:{$resolvedTurnId}";
    }
}
