<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Events\ChatPaused;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\PendingAction;

final readonly class ApprovalContinuationService
{
    private const int CHAIN_HARD_CAP = 5;

    public function dispatchAfterApproval(PendingAction $pendingAction, string $status, bool $bypassChainCap = false): void
    {
        if (! $bypassChainCap && $this->chainCapReached($pendingAction->conversation_id)) {
            if ($pendingAction->conversation_id !== null) {
                broadcast(new ChatPaused(
                    conversationId: (string) $pendingAction->conversation_id,
                    message: 'Paused after several approvals — press Continue to keep going.',
                ));
            }

            return;
        }

        $team = Team::query()->find($pendingAction->team_id);
        $user = User::query()->find($pendingAction->user_id);

        if (! $team instanceof Team || ! $user instanceof User) {
            return;
        }

        dispatch(new ContinueChatMessage(
            user: $user,
            team: $team,
            conversationId: (string) $pendingAction->conversation_id,
            prompt: $this->buildPrompt($pendingAction, $status),
            turnId: (string) Str::ulid(),
        ));
    }

    /**
     * Resume is an explicit user action — it bypasses the chain cap by design.
     */
    public function dispatchContinuation(PendingAction $pendingAction, string $status): void
    {
        $this->dispatchAfterApproval($pendingAction, $status, bypassChainCap: true);
    }

    private function chainCapReached(?string $conversationId): bool
    {
        if ($conversationId === null) {
            return false;
        }

        $recent = DB::table('agent_conversation_messages')
            ->where('conversation_id', $conversationId)
            ->where('role', 'user')->latest()
            ->limit(self::CHAIN_HARD_CAP)
            ->pluck('content')
            ->all();

        if (count($recent) < self::CHAIN_HARD_CAP) {
            return false;
        }

        return array_all($recent, fn (mixed $content): bool => is_string($content) && str_starts_with($content, '[approval]'));
    }

    private function buildPrompt(PendingAction $pendingAction, string $status): string
    {
        $label = $this->resolveLabel($pendingAction) ?? "the {$pendingAction->entity_type} record(s)";

        if ($status !== 'approved') {
            return implode("\n", [
                '[approval]',
                "The user REJECTED the proposal to {$pendingAction->operation->value} {$label}.",
                "Do not silently retry it. Ask the user what they'd prefer instead.",
            ]);
        }

        $lines = [
            '[approval]',
            "The user APPROVED — and the system has already EXECUTED — this action: {$pendingAction->operation->value} {$label}.",
        ];

        $resultData = $pendingAction->result_data;
        $recordId = is_array($resultData) ? ($resultData['id'] ?? null) : null;
        $recordIds = is_array($resultData) ? ($resultData['ids'] ?? null) : null;

        if (is_string($recordId) && $recordId !== '') {
            $lines[] = "Record id: {$recordId} (internal — use for follow-up tool calls, never show it to the user).";
        }

        if (is_array($recordIds) && $recordIds !== []) {
            $lines[] = 'Record ids: '.implode(',', array_map(strval(...), $recordIds)).' (internal — use for follow-up tool calls, never show them to the user).';
        }

        $plan = $pendingAction->display_data['plan'] ?? null;

        if (is_array($plan)
            && is_string($plan['original_request'] ?? null)
            && is_numeric($plan['position'] ?? null)
            && is_numeric($plan['total'] ?? null)) {
            $position = (int) $plan['position'];
            $total = (int) $plan['total'];
            $lines[] = sprintf(
                'Original request: "%s". Progress: %d of %d done. %s',
                $plan['original_request'],
                $position,
                $total,
                $position < $total
                    ? 'Propose the next item now.'
                    : 'Everything requested is done — confirm with a one-line summary naming each record.',
            );
        }

        $lines[] = 'Confirm to the user by the record title(s) above in ONE short sentence. Never echo operation or entity_type tokens as if they were names, never re-list the field values, and never render a table of the data that was just approved.';

        return implode("\n", $lines);
    }

    private function resolveLabel(PendingAction $pendingAction): ?string
    {
        $display = $pendingAction->display_data;

        if (isset($display['items']) && is_array($display['items'])) {
            $titles = array_values(array_filter(array_map(
                static fn (mixed $item): ?string => is_array($item) && is_string($item['summary'] ?? null) ? $item['summary'] : null,
                $display['items'],
            )));
            $count = count($display['items']);
            $shown = implode('; ', array_slice($titles, 0, 5));

            return "{$count} records: {$shown}".($count > 5 ? '; …' : '');
        }

        $data = $pendingAction->action_data;

        foreach (['name', 'title'] as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                return $data[$field];
            }
        }

        return null;
    }
}
