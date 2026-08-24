<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\User;
use Illuminate\Database\QueryException;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\PlanReference;
use RuntimeException;

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
            ->where('team_id', $action->team_id)
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
        return array_values(array_filter(
            $this->steps($action),
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
     * @return list<string>
     */
    public function dependencyIds(PendingAction $step): array
    {
        return PlanReference::targetsIn($step->action_data);
    }

    /**
     * Dependencies that have not produced their record yet, so this step cannot be
     * approved on its own. Empty means the step is ready.
     *
     * @return list<PendingAction>
     */
    public function unmetDependencies(PendingAction $step): array
    {
        $dependencyIds = $this->dependencyIds($step);

        if ($dependencyIds === []) {
            return [];
        }

        $byId = [];

        foreach ($this->steps($step) as $sibling) {
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
     * Approve every remaining step, in order, each in its own transaction.
     *
     * Execution stops at the first failure and reports it: the steps before it are
     * committed and stay committed, because a plan is a sequence of real CRM writes
     * and undoing the earlier ones would be a second set of writes the user never
     * approved.
     *
     * @return array{approved: int, failed: array{step: int, message: string}|null}
     */
    public function approveAll(PendingAction $action, User $user): array
    {
        $approved = 0;
        $steps = $this->steps($action);

        // Counted over the steps actually attempted, i.e. the still-pending ones, which
        // is the same basis the card numbers its rail on. Counting over every proposal
        // of the turn made "Step 3 could not be completed" appear on a card whose steps
        // were labelled 1 and 2 once an earlier step had been approved on its own.
        $position = 0;

        foreach ($steps as $step) {
            $step->refresh();

            if ($step->status !== PendingActionStatus::Pending) {
                continue;
            }

            $position++;

            try {
                $this->approveStep($step, $user);
            } catch (QueryException $exception) {
                // Must precede the RuntimeException arm: QueryException extends
                // PDOException extends RuntimeException, so without this the driver
                // message (the SQL, its bindings and the connection) is what the card
                // renders.
                report($exception);

                return [
                    'approved' => $approved,
                    'failed' => ['step' => $position, 'message' => $this->databaseFailureMessage($exception)],
                ];
            } catch (RuntimeException $exception) {
                return [
                    'approved' => $approved,
                    'failed' => ['step' => $position, 'message' => $exception->getMessage()],
                ];
            }

            $approved++;
        }

        return ['approved' => $approved, 'failed' => null];
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
    public function reject(PendingAction $step): array
    {
        $this->pendingActions->reject($step);

        return $this->cancelDependentsOf($step);
    }

    /**
     * Approve a single step. A step is the unit the card presents, so a step that
     * proposes several records of one type approves all of them: leaving half a
     * step done would be a state the card cannot describe.
     */
    public function approveStep(PendingAction $step, User $user): void
    {
        if (($step->action_data['_batch'] ?? false) !== true) {
            $this->pendingActions->approve($step, $user);

            return;
        }

        $records = is_array($step->action_data['records'] ?? null) ? $step->action_data['records'] : [];

        foreach (array_keys(array_values($records)) as $index) {
            $this->pendingActions->approveItem($step->refresh(), $user, $index);
        }
    }

    /**
     * @return list<PendingAction>
     */
    private function cancelDependentsOf(PendingAction $step): array
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

                $this->pendingActions->cancelStep($candidate, (string) $step->getKey());

                $cancelled[] = $candidate;
                $rejectedIds[] = (string) $candidate->getKey();
                $cancelledThisPass++;
            }
        } while ($cancelledThisPass > 0);

        return $cancelled;
    }
}
