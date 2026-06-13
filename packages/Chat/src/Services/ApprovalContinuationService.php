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
use Relaticle\Chat\Support\PromptText;

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
        $resultData = $pendingAction->result_data;

        if (is_array($resultData) && is_array($resultData['items'] ?? null) && $resultData['items'] !== []) {
            return $this->buildBatchItemsPrompt($pendingAction);
        }

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

    /**
     * Summarize a batch that was resolved item-by-item: which records were
     * created (with internal ids for follow-up) and which were skipped (by name),
     * so the assistant's confirmation reflects the real per-item outcome.
     */
    private function buildBatchItemsPrompt(PendingAction $pendingAction): string
    {
        $resultData = is_array($pendingAction->result_data) ? $pendingAction->result_data : [];
        $items = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];
        $displayItems = is_array($pendingAction->display_data['items'] ?? null) ? $pendingAction->display_data['items'] : [];
        $records = is_array($pendingAction->action_data['records'] ?? null) ? $pendingAction->action_data['records'] : [];

        $created = [];
        $createdIds = [];
        $skipped = [];

        foreach ($items as $index => $outcome) {
            $name = $this->itemName($displayItems, $records, (int) $index);

            if (is_array($outcome) && ($outcome['status'] ?? null) === 'approved') {
                $created[] = $name;

                if (is_string($outcome['id'] ?? null) || is_int($outcome['id'] ?? null)) {
                    $createdIds[] = (string) $outcome['id'];
                }

                continue;
            }

            $skipped[] = $name;
        }

        $lines = ['[approval]'];

        if ($created !== []) {
            $lines[] = 'The user APPROVED — and the system EXECUTED — creating: '.implode(', ', $created).'.';

            if ($createdIds !== []) {
                $lines[] = 'Record ids: '.implode(',', $createdIds).' (internal — use for follow-up tool calls, never show them to the user).';
            }
        }

        if ($skipped !== []) {
            $lines[] = 'The user SKIPPED (did not create): '.implode(', ', $skipped).'. Do not silently retry these.';
        }

        $lines[] = $created === []
            ? 'Nothing was created. In ONE short sentence, acknowledge that and ask what they would like to change.'
            : 'Confirm in ONE short sentence naming each created record by its title; if any were skipped, briefly acknowledge that. Never re-list the field values, never render a table, never echo entity_type tokens as names.';

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, mixed>  $displayItems
     * @param  array<int, mixed>  $records
     */
    private function itemName(array $displayItems, array $records, int $index): string
    {
        $summary = is_array($displayItems[$index] ?? null) ? ($displayItems[$index]['summary'] ?? null) : null;

        if (is_string($summary) && $summary !== '') {
            return PromptText::sanitize($summary, 200);
        }

        $record = $records[$index] ?? null;

        if (is_array($record)) {
            foreach (['name', 'title'] as $field) {
                if (is_string($record[$field] ?? null) && $record[$field] !== '') {
                    return PromptText::sanitize($record[$field], 200);
                }
            }
        }

        return 'record #'.($index + 1);
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
