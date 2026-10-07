<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Support\Arr;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;

final readonly class PendingActionEnvelope
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $beforeDisplay
     * @param  array<string, mixed>  $afterMeta
     * @return array<string, mixed>
     */
    public static function for(PendingAction $pending, string $action, array $data, array $beforeDisplay = [], array $afterMeta = []): array
    {
        $envelope = [
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'turn_id' => $pending->turn_id,
            'action' => $action,
            'entity_type' => $pending->entity_type,
            'operation' => $pending->operation->value,
            'data' => $data,
            ...$beforeDisplay,
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => $pending->isPending()],
            ...$afterMeta,
        ];

        return $pending->isPending() ? $envelope : [...$envelope, ...self::decision($pending)];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decision(PendingAction $pending): array
    {
        $outcome = Arr::only($pending->result_data ?? [], ['id', 'ids', 'items']);
        $verdict = $pending->status === PendingActionStatus::Approved ? 'approved, so it was written' : 'rejected, so nothing was written';

        return [
            'status' => $pending->status->value,
            ...($outcome === [] ? [] : ['outcome' => $outcome]),
            'message' => "The user already decided this exact proposal earlier in this turn: it was {$verdict}. Nothing is pending. Report that outcome to the user and do not propose it again.",
        ];
    }
}
