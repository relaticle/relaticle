<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\PendingAction;

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
    /**
     * The synthetic prompt the resumed turn runs on. It is stored as a user
     * message (that is the only shape the provider accepts as the final turn)
     * but stamped as a continuation so the transcript never renders it as
     * something the user typed. What actually happened travels, as always, in
     * the <resolved_actions> block rather than in this text.
     */
    public const string PROMPT = 'The proposals from your last turn have just been decided. Their outcome is in <resolved_actions>. Confirm what happened in one short sentence, naming each record as a link. If a step of the request is still outstanding and you can act on it now, do it in this turn. If nothing is left, say so and stop.';

    private const int DEDUPE_TTL_SECONDS = 3600;

    public function __construct(
        private CreditService $credits,
        private AiModelResolver $models,
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
     * arrived from the client cannot buy a model the team may not use.
     */
    public function resume(User $user, string $conversationId, string $resolvedTurnId, ?string $model = null): bool
    {
        $team = $user->currentTeam;

        if ($team === null) {
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

        if (! $this->credits->reserveCredit(
            $team,
            reservationKey: "reserve-{$turnId}",
            conversationId: $conversationId,
            userId: (string) $user->getKey(),
        )) {
            Cache::forget($this->dedupeKey($resolvedTurnId));

            return false;
        }

        dispatch(new ProcessChatMessage(
            user: $user,
            team: $team,
            message: self::PROMPT,
            conversationId: $conversationId,
            resolved: $this->models->resolve($user, $model),
            turnId: $turnId,
            isContinuation: true,
            resumesTurnId: $resolvedTurnId,
        ));

        return true;
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
