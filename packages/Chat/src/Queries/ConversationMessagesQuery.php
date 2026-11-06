<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Chat\Support\AttachedRows;
use Relaticle\Chat\Support\DisplayBlocks;
use Relaticle\Chat\Support\MarkdownRenderer;
use Relaticle\Chat\Support\NextSteps;
use Relaticle\Chat\Support\ProposalReceipts;
use Relaticle\Chat\Support\RecordReferenceResolver;
use Relaticle\Chat\Support\StoredSteps;
use Relaticle\Chat\Support\WebSources;
use stdClass;

final readonly class ConversationMessagesQuery
{
    public function __construct(
        private RecordReferenceResolver $resolver,
        private ProposalReceipts $receipts,
        private MarkdownRenderer $markdown = new MarkdownRenderer,
    ) {}

    /**
     * @return array<int, array{id: string, role: string, content: string, document: array<string, mixed>, created_at: ?string, pending_actions: array<int, mixed>, display_blocks: list<array<string, mixed>>, next_steps: list<array{label: string, prompt: string}>, sources: list<array{url: string, title: string|null, domain: string}>, feedback: ?array{rating: string, category: ?string}, mentions: list<array{type: string, id: string, label: string, url: ?string}>, page_context: array{type: string, id: string, label: string, url: string|null}|null, attachment: array{id: string, name: string, kind: string, row_count: int}|null}>
     */
    public function get(User $user, string $conversationId, ?string $beforeMessageId = null, int $limit = 50): array
    {
        $messages = AgentConversationMessage::query()
            ->visibleTo($user, $conversationId)
            ->when($beforeMessageId !== null, fn (Builder $query): Builder => $query->where('id', '<', $beforeMessageId))
            ->orderByDesc('id')
            ->limit($limit)
            ->toBase()
            ->get(['id', 'role', 'content', 'document', 'steps', 'meta', 'created_at'])
            ->reverse()
            ->values();

        $mentionsByMessage = DB::table('agent_conversation_message_mentions')
            ->whereIn('message_id', $messages->pluck('id'))
            ->get(['message_id', 'type', 'record_id', 'label', 'source'])
            ->groupBy('message_id');

        $feedbackByMessage = DB::table('chat_message_feedback')
            ->where('user_id', $user->getKey())
            ->whereIn('message_id', $messages->pluck('id'))
            ->get(['message_id', 'rating', 'category'])
            ->keyBy('message_id');

        $toolResultsByMessage = [];
        $envelopesByMessage = [];
        $pendingIds = [];

        foreach ($messages as $msg) {
            $toolResults = StoredSteps::toolResults($msg->steps);
            $toolResultsByMessage[(string) $msg->id] = $toolResults;

            $envelopes = $this->pendingActionEnvelopes($toolResults);
            $envelopesByMessage[(string) $msg->id] = $envelopes;

            foreach ($envelopes as $inner) {
                if (isset($inner['pending_action_id'])) {
                    $pendingIds[] = (string) $inner['pending_action_id'];
                }
            }
        }

        $pendingIds = array_values(array_unique($pendingIds));

        $records = $this->pendingRecords($pendingIds, $user);

        return $messages->map(function (object $msg) use ($mentionsByMessage, $feedbackByMessage, $toolResultsByMessage, $envelopesByMessage, $records, $user): array {
            $meta = $this->metaFrom($msg->meta);
            $attachment = $this->attachmentFromMeta($meta);

            return [
                'id' => (string) $msg->id,
                'role' => (string) $msg->role,
                'content' => match (true) {
                    $msg->role === 'assistant' => $this->markdown->render((string) ($msg->content ?? '')),
                    $attachment !== null => AttachedRows::typedText((string) ($msg->content ?? '')),
                    default => (string) ($msg->content ?? ''),
                },
                'document' => $this->documentFrom($msg->document ?? null),
                // Normalized to the same ISO 8601 UTC shape (`.toISOString()`) the
                // client mints for optimistic/streamed messages, see send.js and
                // stream.js. The raw DB column value is `Y-m-d H:i:s` with no
                // timezone marker; browsers parse that non-ISO form as LOCAL time,
                // not UTC (a real, silent divergence from the client-minted rows,
                // which are true UTC), so leaving it as-is here would corrupt any
                // client-side comparison across the two message shapes (grouping
                // gaps, day separators, and the bubble tooltips, which were
                // already reading the wrong time before this fix).
                'created_at' => $msg->created_at === null ? null : Date::parse((string) $msg->created_at, 'UTC')->toISOString(),
                'pending_actions' => $this->extractPendingActions($envelopesByMessage[(string) $msg->id] ?? [], $records, (string) $user->current_workspace_id),
                'display_blocks' => DisplayBlocks::collect($toolResultsByMessage[(string) $msg->id]),
                'next_steps' => NextSteps::fromMeta($meta),
                'sources' => WebSources::fromMeta($meta),
                'feedback' => $this->feedbackFrom($feedbackByMessage[$msg->id] ?? null),
                'mentions' => array_values(
                    ($mentionsByMessage[$msg->id] ?? collect())
                        ->filter(fn (stdClass $row): bool => (string) $row->source !== 'page_context')
                        ->map(fn (stdClass $row): array => [
                            'type' => (string) $row->type,
                            'id' => (string) $row->record_id,
                            'label' => (string) $row->label,
                            'url' => $this->resolver->urlFor((string) $row->type, (string) $row->record_id),
                        ])
                        ->all()
                ),
                'page_context' => ($mentionsByMessage[$msg->id] ?? collect())
                    ->filter(fn (stdClass $row): bool => (string) $row->source === 'page_context')
                    ->map(fn (stdClass $row): array => [
                        'type' => (string) $row->type,
                        'id' => (string) $row->record_id,
                        'label' => (string) $row->label,
                        'url' => $this->resolver->urlFor((string) $row->type, (string) $row->record_id),
                    ])
                    ->first(),
                'attachment' => $attachment,
            ];
        })->values()->all();
    }

    /**
     * @param  list<string>  $pendingIds
     * @return array<string, array{status: string, expires_at: ?string, resolved_at: ?string, entity_type: ?string, turn_id: ?string, result_data: ?array<string, mixed>}>
     */
    private function pendingRecords(array $pendingIds, User $user): array
    {
        if ($pendingIds === []) {
            return [];
        }

        return DB::table('pending_actions')
            ->whereIn('id', $pendingIds)
            ->where('user_id', $user->getKey())
            ->where('workspace_id', $user->current_workspace_id)
            ->get(['id', 'status', 'entity_type', 'turn_id', 'result_data', 'expires_at', 'resolved_at'])
            ->keyBy('id')
            ->map(fn (stdClass $row): array => [
                'status' => (string) $row->status,
                'expires_at' => $row->expires_at === null ? null : Date::parse((string) $row->expires_at)->toIso8601String(),
                'resolved_at' => $row->resolved_at === null ? null : Date::parse((string) $row->resolved_at, 'UTC')->toIso8601String(),
                'entity_type' => $row->entity_type === null ? null : (string) $row->entity_type,
                'turn_id' => $row->turn_id === null ? null : (string) $row->turn_id,
                'result_data' => $row->result_data === null ? null : (function (mixed $raw): ?array {
                    $decoded = json_decode((string) $raw, true);

                    return is_array($decoded) ? $decoded : null;
                })($row->result_data),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function documentFrom(mixed $raw): array
    {
        $decoded = $raw === null ? null : json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : ['type' => 'doc', 'content' => []];
    }

    /**
     * @return array{rating: string, category: ?string}|null
     */
    private function feedbackFrom(?stdClass $row): ?array
    {
        return $row instanceof stdClass ? [
            'rating' => (string) $row->rating,
            'category' => $row->category === null ? null : (string) $row->category,
        ] : null;
    }

    /**
     * @return array<mixed>
     */
    private function metaFrom(mixed $raw): array
    {
        $decoded = $raw === null ? null : json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<mixed>  $meta
     * @return array{id: string, name: string, kind: 'text'|'rows', row_count: int}|null
     */
    private function attachmentFromMeta(array $meta): ?array
    {
        $attachment = $meta['attachment'] ?? null;

        if (! is_array($attachment) || ! is_string($attachment['id'] ?? null)) {
            return null;
        }

        return [
            'id' => $attachment['id'],
            'name' => (string) ($attachment['name'] ?? ''),
            'kind' => ($attachment['kind'] ?? null) === 'text' ? 'text' : 'rows',
            'row_count' => (int) ($attachment['row_count'] ?? 0),
        ];
    }

    /**
     * The decoded `pending_action` envelopes inside a message's tool results,
     * parsed once and shared by the id collection and the card extraction.
     *
     * @param  list<array<string, mixed>>  $toolResults
     * @return list<array<string, mixed>>
     */
    private function pendingActionEnvelopes(array $toolResults): array
    {
        $envelopes = [];

        foreach ($toolResults as $toolResult) {
            if (! isset($toolResult['result'])) {
                continue;
            }

            $inner = json_decode((string) $toolResult['result'], true);

            if (is_array($inner) && ($inner['type'] ?? null) === 'pending_action') {
                $envelopes[] = $inner;
            }
        }

        return $envelopes;
    }

    /**
     * @param  list<array<string, mixed>>  $envelopes
     * @param  array<string, array{status: string, expires_at: ?string, resolved_at: ?string, entity_type: ?string, turn_id: ?string, result_data: ?array<string, mixed>}>  $records
     * @return array<int, mixed>
     */
    private function extractPendingActions(array $envelopes, array $records, string $workspaceId): array
    {
        $actions = [];
        $steps = [];

        foreach ($envelopes as $inner) {
            $pendingId = (string) ($inner['pending_action_id'] ?? '');
            $info = $records[$pendingId] ?? null;
            $inner['status'] = $info['status'] ?? 'expired';
            // The client hides the composer while a proposal is pending and has no
            // other way to learn this one lapsed: the sweeper is a bulk update that
            // broadcasts nothing, so a tab left open would dock it forever.
            $inner['expires_at'] = $info['expires_at'] ?? null;
            // The turn groups the proposals of one chained request into a single
            // plan card; the stored row is authoritative, since a tool result
            // written before plans existed carries no turn.
            $inner['turn_id'] = $info['turn_id'] ?? ($inner['turn_id'] ?? null);

            $resultData = is_array($info['result_data'] ?? null) ? $info['result_data'] : null;
            $entityType = $info['entity_type'] ?? (isset($inner['entity_type']) ? (string) $inner['entity_type'] : null);

            if ($inner['status'] === 'approved' && $info !== null) {
                $inner = $this->withRecordRefs($inner, $resultData, $entityType);
            }

            // A step cancelled because a step it depended on was rejected reads as an
            // unexplained disappearance unless the card can say why.
            if (is_string($resultData['cancelled_by'] ?? null)) {
                $inner['cancelled_by'] = $resultData['cancelled_by'];
            }

            $steps[$pendingId] = ProposalReceipts::step(
                $pendingId,
                $inner['status'],
                $entityType,
                isset($inner['operation']) ? (string) $inner['operation'] : null,
                $workspaceId,
                $info['resolved_at'] ?? null,
                $resultData,
                is_array($inner['display'] ?? null) ? $inner['display'] : null,
            );

            $actions[] = $inner;
        }

        $receipts = $this->receiptsByGroup($actions, $steps);

        return array_map(fn (array $action): array => $this->withReceipt($action, $receipts[(string) ($action['pending_action_id'] ?? '')] ?? null, $steps[(string) ($action['pending_action_id'] ?? '')] ?? null), $actions);
    }

    /**
     * @param  array<string, mixed>  $inner
     * @param  array<string, mixed>|null  $resultData
     * @return array<string, mixed>
     */
    private function withRecordRefs(array $inner, ?array $resultData, ?string $entityType): array
    {
        $recordId = $resultData['id'] ?? null;

        if ((is_string($recordId) || is_int($recordId)) && is_string($entityType)) {
            $ref = $this->resolver->resolve($entityType, (string) $recordId);
            if ($ref !== null) {
                $inner['record'] = $ref;
            }
        }

        $batchIds = $resultData['ids'] ?? null;

        if (is_array($batchIds) && $batchIds !== [] && is_string($entityType)) {
            $refs = $this->resolver->resolveMany($entityType, $batchIds);
            if ($refs !== []) {
                $inner['records'] = $refs;
            }
        }

        return $inner;
    }

    /**
     * Receipts are computed per plan (one `turn_id`), because what happened to a
     * step depends on its siblings.
     *
     * @param  list<array<string, mixed>>  $actions
     * @param  array<string, array{id: string, status: string, entity_type: ?string, operation: ?string, workspace_id: string, resolved_at: ?string, result_data: ?array<array-key, mixed>, display: ?array<array-key, mixed>}>  $steps
     * @return array<string, array<string, mixed>>
     */
    private function receiptsByGroup(array $actions, array $steps): array
    {
        $groups = [];

        foreach ($actions as $action) {
            $id = (string) ($action['pending_action_id'] ?? '');
            $groups[(string) ($action['turn_id'] ?? $id)][] = $steps[$id];
        }

        $receipts = [];

        foreach ($groups as $group) {
            $receipts = [...$receipts, ...$this->receipts->forGroup($group)];
        }

        return $receipts;
    }

    /**
     * @param  array<string, mixed>  $action
     * @param  array<string, mixed>|null  $receipt
     * @param  array<string, mixed>|null  $step
     * @return array<string, mixed>
     */
    private function withReceipt(array $action, ?array $receipt, ?array $step): array
    {
        if ($receipt === null || $step === null) {
            return $action;
        }

        $action['outcome'] = $receipt['outcome'];
        $action['resolved_at'] = $receipt['resolved_at'];
        $action['excluded'] = $receipt['excluded'];
        $action['moved_to'] = $receipt['moved_to'];
        $action['moved_total'] = $receipt['moved_total'];

        $itemResults = $receipt['itemResults'];

        if ($itemResults !== []) {
            $action['itemResults'] = $this->withRecords($itemResults, $step['result_data'], $step['entity_type'], $step['operation']);
        }

        return $action;
    }

    /**
     * A deleted record has no page to link to, so delete items carry no ref.
     *
     * @param  array<string, array<string, mixed>>  $itemResults
     * @param  array<string, mixed>|null  $resultData
     * @return array<string, array<string, mixed>>
     */
    private function withRecords(array $itemResults, ?array $resultData, ?string $entityType, ?string $operation): array
    {
        $items = is_array($resultData['items'] ?? null) ? $resultData['items'] : [];

        foreach ($itemResults as $index => $result) {
            $recordId = $items[$index]['id'] ?? null;
            $record = null;

            if ($result['status'] === 'approved' && $operation !== 'delete' && (is_string($recordId) || is_int($recordId)) && is_string($entityType)) {
                $record = $this->resolver->resolve($entityType, (string) $recordId);
            }

            $itemResults[$index]['record'] = $record;
        }

        return $itemResults;
    }
}
