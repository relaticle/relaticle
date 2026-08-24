<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\PlanReference;
use RuntimeException;

/**
 * Replaces every `$ref:<pending_action_id>` in a proposal's payload with the id of
 * the record that step actually created.
 *
 * This runs at approval time, inside the approving transaction. A reference whose
 * step has not been approved yet is an error, never a silently dropped link: the
 * user approved a card that said "Company: Acme Robotics", so writing the record
 * without that link would not be the change they agreed to.
 */
final readonly class PlanReferenceResolver
{
    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function resolve(array $data, PendingAction $context): array
    {
        if (PlanReference::targetsIn($data) === []) {
            return $data;
        }

        return PlanReference::rewrite($data, fn (string $target): string => $this->recordIdFor($target, $context));
    }

    private function recordIdFor(string $target, PendingAction $context): string
    {
        $referenced = PendingAction::query()
            ->whereKey($target)
            ->where('team_id', $context->team_id)
            ->first();

        throw_unless(
            $referenced instanceof PendingAction,
            RuntimeException::class,
            __('A step this one depends on is no longer available. Ask the assistant to propose it again.'),
        );

        /** @var PendingAction $referenced */
        if ($referenced->status !== PendingActionStatus::Approved) {
            throw new RuntimeException(__('Approve the earlier step this one links to first.'));
        }

        $recordId = is_array($referenced->result_data) ? ($referenced->result_data['id'] ?? null) : null;

        throw_unless(
            (is_string($recordId) || is_int($recordId)) && $recordId !== '',
            RuntimeException::class,
            __('The earlier step this one links to did not produce a record.'),
        );

        return (string) $recordId;
    }
}
