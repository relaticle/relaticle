<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Support\MarkdownRenderer;
use Relaticle\Chat\Support\RecordReferenceResolver;
use stdClass;

final readonly class ListConversationMessages
{
    public function __construct(
        private RecordReferenceResolver $resolver,
        private MarkdownRenderer $markdown = new MarkdownRenderer,
    ) {}

    /**
     * @return array<int, array{id: string, role: string, content: string, document: array<string, mixed>, created_at: ?string, pending_actions: array<int, mixed>, feedback: ?array{rating: string, category: ?string}, mentions: list<array{type: string, id: string, label: string, url: ?string}>}>
     */
    public function execute(User $user, string $conversationId, ?string $beforeMessageId = null, int $limit = 50): array
    {
        $query = DB::table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('m.conversation_id', $conversationId)
            ->where('m.user_id', $user->getKey())
            ->where('c.team_id', $user->current_team_id)
            ->whereNull('m.superseded_at');

        if ($beforeMessageId !== null) {
            $query->where('m.id', '<', $beforeMessageId);
        }

        $messages = $query
            ->orderByDesc('m.id')
            ->limit($limit)
            ->get(['m.id', 'm.role', 'm.content', 'm.document', 'm.tool_results', 'm.created_at'])
            ->reverse()
            ->reject(fn (object $msg): bool => (string) $msg->role === 'user'
                && str_starts_with((string) ($msg->content ?? ''), '[approval]'))
            ->values();

        $mentionsByMessage = DB::table('agent_conversation_message_mentions')
            ->whereIn('message_id', $messages->pluck('id'))
            ->get(['message_id', 'type', 'record_id', 'label'])
            ->groupBy('message_id');

        $feedbackByMessage = DB::table('chat_message_feedback')
            ->where('user_id', $user->getKey())
            ->whereIn('message_id', $messages->pluck('id'))
            ->get(['message_id', 'rating', 'category'])
            ->keyBy('message_id');

        $pendingIds = $this->collectPendingActionIds($messages);

        /** @var array<string, array{status: string, entity_type: ?string, result_data: ?array<string, mixed>}> $records */
        $records = $pendingIds === []
            ? []
            : DB::table('pending_actions')
                ->whereIn('id', $pendingIds)
                ->where('user_id', $user->getKey())
                ->where('team_id', $user->current_team_id)
                ->get(['id', 'status', 'entity_type', 'result_data'])
                ->keyBy('id')
                ->map(fn (object $row): array => [
                    'status' => (string) $row->status,
                    'entity_type' => $row->entity_type === null ? null : (string) $row->entity_type,
                    'result_data' => $row->result_data === null ? null : (function (mixed $raw): ?array {
                        $decoded = json_decode((string) $raw, true);

                        return is_array($decoded) ? $decoded : null;
                    })($row->result_data),
                ])
                ->all();

        return $messages->map(fn (object $msg): array => [
            'id' => (string) $msg->id,
            'role' => (string) $msg->role,
            'content' => $msg->role === 'assistant'
                ? $this->markdown->render((string) ($msg->content ?? ''))
                : (string) ($msg->content ?? ''),
            'document' => (function (mixed $raw): array {
                if ($raw === null) {
                    return ['type' => 'doc', 'content' => []];
                }
                $decoded = json_decode((string) $raw, true);

                return is_array($decoded) ? $decoded : ['type' => 'doc', 'content' => []];
            })($msg->document ?? null),
            'created_at' => $msg->created_at === null ? null : (string) $msg->created_at,
            'pending_actions' => $this->extractPendingActions(
                $msg->tool_results === null ? null : (string) $msg->tool_results,
                $records,
            ),
            'feedback' => isset($feedbackByMessage[$msg->id]) ? [
                'rating' => (string) $feedbackByMessage[$msg->id]->rating,
                'category' => $feedbackByMessage[$msg->id]->category === null ? null : (string) $feedbackByMessage[$msg->id]->category,
            ] : null,
            'mentions' => array_values(
                ($mentionsByMessage[$msg->id] ?? collect())
                    ->map(fn (object $row): array => [
                        'type' => (string) $row->type,
                        'id' => (string) $row->record_id,
                        'label' => (string) $row->label,
                        'url' => $this->resolver->urlFor((string) $row->type, (string) $row->record_id),
                    ])
                    ->all()
            ),
        ])->values()->all();
    }

    /**
     * @param  Collection<int, stdClass>  $messages
     * @return list<string>
     */
    private function collectPendingActionIds(Collection $messages): array
    {
        $ids = [];

        foreach ($messages as $msg) {
            $rawToolResults = $msg->tool_results ?? null;
            $parsed = json_decode((string) ($rawToolResults ?? 'null'), true);

            if (! is_array($parsed)) {
                continue;
            }

            foreach ($parsed as $toolResult) {
                if (! is_array($toolResult)) {
                    continue;
                }
                if (! isset($toolResult['result'])) {
                    continue;
                }
                $inner = json_decode((string) $toolResult['result'], true);

                if (is_array($inner) && ($inner['type'] ?? null) === 'pending_action' && isset($inner['pending_action_id'])) {
                    $ids[] = (string) $inner['pending_action_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string, array{status: string, entity_type: ?string, result_data: ?array<string, mixed>}>  $records
     * @return array<int, mixed>
     */
    private function extractPendingActions(?string $toolResults, array $records): array
    {
        if ($toolResults === null) {
            return [];
        }

        $parsed = json_decode($toolResults, true);

        if (! is_array($parsed)) {
            return [];
        }

        $actions = [];

        foreach ($parsed as $toolResult) {
            if (! is_array($toolResult)) {
                continue;
            }
            if (! isset($toolResult['result'])) {
                continue;
            }
            $inner = json_decode((string) $toolResult['result'], true);
            if (! is_array($inner)) {
                continue;
            }
            if (($inner['type'] ?? null) !== 'pending_action') {
                continue;
            }

            $pendingId = (string) ($inner['pending_action_id'] ?? '');
            $info = $records[$pendingId] ?? null;
            $inner['status'] = $info['status'] ?? 'expired';

            $resultData = is_array($info['result_data'] ?? null) ? $info['result_data'] : null;
            $entityType = $info['entity_type'] ?? (isset($inner['entity_type']) ? (string) $inner['entity_type'] : null);

            if ($inner['status'] === 'approved' && $info !== null) {
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
            }

            $items = is_array($resultData['items'] ?? null) ? $resultData['items'] : null;

            if ($items !== null) {
                $operation = isset($inner['operation']) ? (string) $inner['operation'] : null;
                $itemResults = $this->reconstructItemResults($items, $entityType, $operation);

                if ($itemResults !== []) {
                    $inner['itemResults'] = $itemResults;
                }
            }

            $actions[] = $inner;
        }

        return $actions;
    }

    /**
     * Mirror the live frontend `applyProposalResolution` mapping so per-item batch
     * chips survive a conversation reload: stored 'approved' stays 'approved' (with
     * a resolved record ref), stored 'rejected' becomes the 'skipped' chip. A deleted
     * record has no page to link to, so delete items carry no ref.
     *
     * @param  array<array-key, mixed>  $items  the persisted result_data['items'], keyed by item index
     * @return array<string, array{status: string, record: array{id: string, type: string, url: string, label: ?string}|null}>
     */
    private function reconstructItemResults(array $items, ?string $entityType, ?string $operation = null): array
    {
        $itemResults = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $storedStatus = $item['status'] ?? null;
            $chipStatus = match ($storedStatus) {
                'approved' => 'approved',
                'rejected' => 'skipped',
                default => null,
            };

            if ($chipStatus === null) {
                continue;
            }

            $record = null;
            $recordId = $item['id'] ?? null;

            if ($chipStatus === 'approved' && $operation !== 'delete' && (is_string($recordId) || is_int($recordId)) && is_string($entityType)) {
                $record = $this->resolver->resolve($entityType, (string) $recordId);
            }

            $itemResults[(string) $index] = [
                'status' => $chipStatus,
                'record' => $record,
            ];
        }

        return $itemResults;
    }
}
