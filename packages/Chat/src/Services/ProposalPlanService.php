<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\ApprovalFailureMessage;
use Relaticle\Chat\Support\PlanReference;
use Relaticle\Chat\Support\ProposalPayload;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * A plan is the set of proposals one assistant turn produced.
 *
 * The assistant chains dependent writes inside a single turn ("create the
 * company, then the contact there, then the task"), so those proposals share a
 * turn id and the later ones reference the earlier ones by proposal id. This
 * service is what turns that flat set into something the dock can present and
 * resolve as one decision: ordered steps, dependency edges, one approval that
 * walks them in order, and a rejection that cancels whatever depended on it.
 */
final readonly class ProposalPlanService
{
    public function __construct(private PendingActionService $pendingActions) {}

    /**
     * Every step of the plan the given proposal belongs to, in creation order.
     * A proposal with no turn id (or the only one in its turn) is a plan of one,
     * which is exactly how a single proposal behaved before plans existed.
     *
     * @return list<PendingAction>
     */
    public function steps(PendingAction $action): array
    {
        if ($action->turn_id === null) {
            return [$action];
        }

        $steps = PendingAction::query()
            ->where('workspace_id', $action->workspace_id)
            ->where('user_id', $action->user_id)
            ->where('conversation_id', $action->conversation_id)
            ->where('turn_id', $action->turn_id)
            ->orderBy('id')
            ->get()
            ->all();

        return $steps === [] ? [$action] : array_values($steps);
    }

    /**
     * The steps still awaiting a decision, in order.
     *
     * @return list<PendingAction>
     */
    public function pendingSteps(PendingAction $action): array
    {
        return $this->pendingAmong($this->steps($action));
    }

    /**
     * The same predicate over a plan the caller already holds, so the dock can
     * filter its memo instead of keeping a second copy of what "undecided" means.
     *
     * @param  list<PendingAction>  $steps
     * @return list<PendingAction>
     */
    public function pendingAmong(array $steps): array
    {
        return array_values(array_filter(
            $steps,
            static fn (PendingAction $step): bool => $step->status === PendingActionStatus::Pending && ! $step->isExpired(),
        ));
    }

    public function isPlan(PendingAction $action): bool
    {
        return count($this->steps($action)) > 1;
    }

    /**
     * Proposal ids this step needs before it can run.
     *
     * Bare ids, with any `#<index>` suffix stripped: a reference into a batched
     * proposal still depends on that whole proposal, and two references into the
     * same batch are one dependency, not two.
     *
     * @return list<string>
     */
    public function dependencyIds(PendingAction $step): array
    {
        return array_values(array_unique(array_map(
            PlanReference::actionId(...),
            PlanReference::targetsIn($step->action_data),
        )));
    }

    /**
     * The dependencies of $step that are not yet approved.
     *
     * The caller passes the plan's steps rather than having this reload them:
     * the dock asks once per step while rendering, and re-reading the whole plan
     * inside the loop put one full plan SELECT per step on every round trip.
     * Pass steps(), not pendingSteps(): an approved dependency has to be visible
     * here to count as met.
     *
     * @param  list<PendingAction>  $siblings
     * @return list<PendingAction>
     */
    public function unmetDependencies(PendingAction $step, array $siblings): array
    {
        $dependencyIds = $this->dependencyIds($step);

        if ($dependencyIds === []) {
            return [];
        }

        $byId = [];

        foreach ($siblings as $sibling) {
            $byId[(string) $sibling->getKey()] = $sibling;
        }

        $unmet = [];

        foreach ($dependencyIds as $dependencyId) {
            $dependency = $byId[$dependencyId] ?? null;

            if ($dependency instanceof PendingAction && $dependency->status !== PendingActionStatus::Approved) {
                $unmet[] = $dependency;
            }
        }

        return $unmet;
    }

    /**
     * @return array{approved: list<PendingAction>, failed: array{step: PendingAction, message: string}|null}
     */
    public function approveAll(PendingAction $action, User $user): array
    {
        $approved = [];

        foreach ($this->steps($action) as $step) {
            $step->refresh();

            if ($step->status !== PendingActionStatus::Pending || $step->needsOwnApproval()) {
                continue;
            }

            try {
                $this->approveStep($step, $user);
            } catch (QueryException $exception) {
                report($exception);

                return $this->failure($approved, $step, $this->databaseFailureMessage($exception));
            } catch (TransportExceptionInterface $exception) {
                report($exception);

                return $this->failure($approved, $step, ApprovalFailureMessage::forDelivery());
            } catch (RuntimeException|ValidationException $exception) {
                return $this->failure($approved, $step, ApprovalFailureMessage::for($exception));
            }

            $approved[] = $step;
        }

        return ['approved' => $approved, 'failed' => null];
    }

    /**
     * @param  list<PendingAction>  $approved
     * @return array{approved: list<PendingAction>, failed: array{step: PendingAction, message: string}}
     */
    private function failure(array $approved, PendingAction $step, string $message): array
    {
        return ['approved' => $approved, 'failed' => ['step' => $step, 'message' => $message]];
    }

    /**
     * The user-facing stand-in for a driver error. Mirrors what
     * ProposalCard::reportDatabaseFailure() renders for a single proposal, so the
     * plan path and the single-proposal path say the same thing.
     */
    public function databaseFailureMessage(QueryException $exception): string
    {
        return $exception->getCode() === '23505'
            ? __('Someone else just made a conflicting change. Reload the page and try again.')
            : __('This change could not be saved. Please try again.');
    }

    /**
     * Reject one step and cancel everything that depended on it, directly or
     * transitively. Approving a task whose contact was just rejected would write a
     * record the user never agreed to, so the dependents go with it.
     *
     * @return list<PendingAction> The cancelled dependents, not including $step.
     */
    public function reject(PendingAction $step, User $user): array
    {
        $this->pendingActions->reject($step, $user);

        return $this->cancelDependentsOf($step, $user);
    }

    /**
     * Approve a single step. A step is the unit the card presents, so a step that
     * proposes several records of one type approves all of them: leaving half a
     * step done would be a state the card cannot describe.
     */
    public function approveStep(PendingAction $step, User $user): void
    {
        $payload = ProposalPayload::from($step);

        if (! $payload->isBatch) {
            $this->pendingActions->approve($step, $user);

            return;
        }

        // A batch row shows only its active record in full, so one click must
        // never send a message nobody opened.
        throw_if($step->isEmailSend(), RuntimeException::class, __('Each email is approved on its own.'));

        foreach (array_keys($payload->batchRecords()) as $index) {
            $this->pendingActions->approveItem($step->refresh(), $user, $index);
        }
    }

    /**
     * @return list<PendingAction>
     */
    private function cancelDependentsOf(PendingAction $step, User $user): array
    {
        $cancelled = [];
        $rejectedIds = [(string) $step->getKey()];

        // A dependency chain is short (bounded by the plan's step count) but it is a
        // chain: cancelling the contact must also cancel the task that linked to it.
        do {
            $cancelledThisPass = 0;

            foreach ($this->pendingSteps($step) as $candidate) {
                $dependsOnRejected = array_intersect($this->dependencyIds($candidate), $rejectedIds) !== [];

                if (! $dependsOnRejected) {
                    continue;
                }

                // The saved model, not the stale candidate: it already carries the
                // Rejected status and the `cancelled_by` the card renders, so the
                // announcement does not have to re-read the row it just wrote.
                $cancelled[] = $this->pendingActions->cancelStep($candidate, $user, (string) $step->getKey());
                $rejectedIds[] = (string) $candidate->getKey();
                $cancelledThisPass++;
            }
        } while ($cancelledThisPass > 0);

        return $cancelled;
    }
}
