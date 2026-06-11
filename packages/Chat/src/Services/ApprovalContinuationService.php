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

    public function dispatchAfterApproval(PendingAction $pendingAction, string $status): void
    {
        if ($this->chainCapReached($pendingAction->conversation_id)) {
            if ($pendingAction->conversation_id !== null) {
                broadcast(new ChatPaused(
                    conversationId: (string) $pendingAction->conversation_id,
                    message: 'Paused after several approvals — press Continue to keep going.',
                ));
            }

            return;
        }

        $this->dispatchContinuation($pendingAction, $status);
    }

    /**
     * Resume is an explicit user action, so it bypasses the chain cap — it IS
     * the cap's escape hatch.
     */
    public function dispatchContinuation(PendingAction $pendingAction, string $status): void
    {
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
        $lines = [
            '[approval]',
            "status: {$status}",
            "operation: {$pendingAction->operation->value}",
            "entity_type: {$pendingAction->entity_type}",
        ];

        if ($status === 'approved') {
            $resultData = $pendingAction->result_data;
            $recordId = is_array($resultData) ? ($resultData['id'] ?? null) : null;
            if (is_string($recordId) && $recordId !== '') {
                $lines[] = "record_id: {$recordId}";
            }

            $recordIds = is_array($resultData) ? ($resultData['ids'] ?? null) : null;
            if (is_array($recordIds) && $recordIds !== []) {
                $lines[] = 'record_ids: '.implode(',', array_map(strval(...), $recordIds));
            }

            $label = $this->resolveLabel($pendingAction);
            if ($label !== null) {
                $lines[] = "record_label: {$label}";
            }
        } else {
            $lines[] = 'note: User rejected the proposed action. Ask before reproposing.';
        }

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
