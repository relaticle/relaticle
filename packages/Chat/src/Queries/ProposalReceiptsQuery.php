<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use App\Enums\CustomFields\OpportunityField;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Enums\ProposalOutcome;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\ProposalPlanService;

/**
 * The facts a transcript receipt needs about one plan, built once for both the
 * reload path (ConversationMessagesQuery) and the live resolution payloads, so
 * the browser reads them and derives none of its own.
 *
 * @phpstan-type Step array{id: string, status: string, entity_type: ?string, operation: ?string, workspace_id: string, resolved_at: ?string, result_data: ?array<array-key, mixed>, display: ?array<array-key, mixed>}
 * @phpstan-type Receipt array{outcome: ?string, resolved_at: ?string, excluded: list<string>, moved_to: ?string, moved_total: ?string, itemResults: array<string, array{status: string, outcome: string, excluded: list<string>}>}
 */
final readonly class ProposalReceiptsQuery
{
    public function __construct(
        private ProposalPlanService $plans,
        private OpportunityAmountTotalQuery $amounts,
    ) {}

    /**
     * What the transcript shows for every step of the plan the step belongs to.
     *
     * @return array<string, Receipt>
     */
    public function forPlanOf(PendingAction $step): array
    {
        $fresh = $step->fresh() ?? $step;

        return $this->forGroups([array_map($this->stepFromModel(...), $this->plans->steps($fresh))]);
    }

    /**
     * @return array<string, Receipt>
     */
    public function forPlanBroadcastOf(PendingAction $step): array
    {
        // Reverb caps a message at 10 KB, so a decided step drops the rows it applied as
        // proposed; transcript.js reads a missing row as applied.
        $receipts = $this->forPlanOf($step);

        foreach ($receipts as $id => $receipt) {
            if ($receipt['outcome'] === null) {
                continue;
            }

            $receipts[$id]['itemResults'] = array_filter(
                $receipt['itemResults'],
                fn (array $row): bool => $row['outcome'] !== ProposalOutcome::Applied->value || $row['excluded'] !== [],
            );
        }

        return $receipts;
    }

    /**
     * Only this step's receipt: what a row decision changes. A pending step has
     * no outcome yet, so no sibling can alter it.
     *
     * @return array<string, Receipt>
     */
    public function forStep(PendingAction $step): array
    {
        return $this->forGroups([[$this->stepFromModel($step->fresh() ?? $step)]]);
    }

    /**
     * Many plans at once, so the moved totals of all of them cost one query.
     *
     * @param  list<list<Step>>  $groups
     * @return array<string, Receipt>
     */
    public function forGroups(array $groups): array
    {
        $receipts = [];
        $movedIds = [];

        foreach ($groups as $steps) {
            $outcomes = $this->outcomes($steps);

            foreach ($steps as $step) {
                $entity = ProposalEntity::tryFrom((string) $step['entity_type']);
                $resultData = $step['result_data'] ?? [];
                $items = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];
                $moved = $this->moved($step, $entity, $items);

                if ($moved !== null) {
                    $movedIds[$step['workspace_id']][$step['id']] = $moved['ids'];
                }

                $receipts[$step['id']] = [
                    'outcome' => $outcomes[$step['id']]?->value,
                    'resolved_at' => $step['resolved_at'],
                    'excluded' => $this->codes($resultData['excluded'] ?? null),
                    'moved_to' => $moved['to'] ?? null,
                    'moved_total' => null,
                    'itemResults' => $this->itemResults($items, $entity),
                ];
            }
        }

        $totals = [];

        foreach ($movedIds as $workspaceId => $idsByStep) {
            $totals += $this->amounts->formatted((string) $workspaceId, $idsByStep);
        }

        $complete = [];

        foreach ($receipts as $id => $receipt) {
            $complete[$id] = [...$receipt, 'moved_total' => $totals[$id] ?? null];
        }

        return $complete;
    }

    /**
     * @param  list<Step>  $steps
     * @return array<string, ?ProposalOutcome>
     */
    private function outcomes(array $steps): array
    {
        $decided = [];

        foreach ($steps as $step) {
            $decided[$step['id']] = [
                'status' => PendingActionStatus::tryFrom($step['status']) ?? PendingActionStatus::Pending,
                'entity' => ProposalEntity::tryFrom((string) $step['entity_type']),
            ];
        }

        return ProposalOutcome::forGroup($decided);
    }

    /**
     * @param  array<array-key, mixed>|null  $resultData
     * @param  array<array-key, mixed>|null  $display
     * @return Step
     */
    public static function step(string $id, string $status, ?string $entityType, ?string $operation, string $workspaceId, ?string $resolvedAt, ?array $resultData, ?array $display): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'entity_type' => $entityType,
            'operation' => $operation,
            'workspace_id' => $workspaceId,
            'resolved_at' => $resolvedAt,
            'result_data' => $resultData,
            'display' => $display,
        ];
    }

    /**
     * @return Step
     */
    private function stepFromModel(PendingAction $step): array
    {
        return self::step(
            (string) $step->getKey(),
            $step->status->value,
            $step->entity_type->value,
            $step->operation->value,
            (string) $step->workspace_id,
            $step->resolved_at?->toIso8601String(),
            $step->result_data,
            $step->display_data,
        );
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array<string, array{status: string, outcome: string, excluded: list<string>}>
     */
    private function itemResults(array $items, ?ProposalEntity $entity): array
    {
        $declined = ($entity?->declinedOutcome() ?? ProposalOutcome::LeftOut)->value;
        $results = [];

        foreach ($items as $index => $item) {
            $stored = is_array($item) ? ($item['status'] ?? null) : null;

            if ($stored !== 'approved' && $stored !== 'rejected') {
                continue;
            }

            $results[(string) $index] = [
                'status' => $stored === 'approved' ? 'approved' : 'skipped',
                'outcome' => $stored === 'approved' ? ProposalOutcome::Applied->value : $declined,
                'excluded' => $this->codes($item['excluded'] ?? null),
            ];
        }

        return $results;
    }

    /**
     * A batch of opportunity updates is a move when every written row changed
     * only the stage, to one value, decided on the field code.
     *
     * @param  Step  $step
     * @param  array<array-key, mixed>  $items
     * @return array{to: string, ids: list<string>}|null
     */
    private function moved(array $step, ?ProposalEntity $entity, array $items): ?array
    {
        if ($entity !== ProposalEntity::Opportunity || $step['operation'] !== PendingActionOperation::Update->value || $step['status'] !== PendingActionStatus::Approved->value) {
            return null;
        }

        $rows = is_array($step['display']['items'] ?? null) ? $step['display']['items'] : [];
        $targets = [];
        $ids = [];

        foreach ($rows as $index => $row) {
            $result = $items[$index] ?? null;

            if ($items !== [] && (! is_array($result) || ($result['status'] ?? null) !== 'approved')) {
                continue;
            }

            $excluded = $this->codes(is_array($result) ? ($result['excluded'] ?? null) : null);
            $fields = array_values(array_filter(
                is_array($row['fields'] ?? null) ? $row['fields'] : [],
                fn (mixed $field): bool => is_array($field) && ! in_array($field['code'] ?? null, $excluded, true),
            ));

            if (count($fields) !== 1 || ($fields[0]['code'] ?? null) !== OpportunityField::STAGE->value || ! is_string($fields[0]['new'] ?? null)) {
                return null;
            }

            $targets[] = $fields[0]['new'];

            if (is_array($result) && is_string($result['id'] ?? null)) {
                $ids[] = $result['id'];
            }
        }

        $targets = array_unique($targets);

        return count($targets) === 1 ? ['to' => reset($targets), 'ids' => $ids] : null;
    }

    /**
     * @return list<string>
     */
    private function codes(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
