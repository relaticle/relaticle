<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\LazyCollection;
use Laravel\Ai\Storage\StoredMessage;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Enums\RefundCause;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Models\AgentConversationMessage;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\TurnNotes;
use stdClass;

/**
 * @phpstan-type Report array{
 *   turns: int, typed_turns: int, error_turns: int, timeouts: int, blank_replies: int,
 *   tool_calls: int, tool_errors: array<string, int>, reference_errors: int,
 *   proposals: array<string, int>,
 *   setup_conversations: int, setup_conversations_typed: int,
 *   cache: array<string, array{followups: int, zero_read: int, read_share: float}>,
 *   top_workspaces: list<array{workspace_id: string, turns: int, prompt_tokens: int}>,
 *   spend: Spend,
 *   refunds: array{by_cause: array<string, int>, provider_billed: int, most_in_one_workspace: int}
 * }
 *
 * @phpstan-import-type Spend from ChatSpendQuery
 */
final readonly class ChatHealthQuery
{
    private const int FOLLOW_UP_SECONDS = 300;

    private const int TOP_WORKSPACES = 5;

    public function __construct(private ChatSpendQuery $spend) {}

    /** @return Report */
    public function forWindow(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'turns' => $this->assistantTurns($from, $to)->count(),
            'typed_turns' => AgentConversationMessage::query()->typed()->whereBetween('created_at', [$from, $to])->count(),
            'error_turns' => $this->assistantTurns($from, $to)->errored()->count(),
            'timeouts' => $this->timeouts($from, $to),
            ...$this->scanTurns($from, $to),
            'proposals' => $this->proposals($from, $to),
            ...$this->setupConversations($from, $to),
            'spend' => $this->spend->forWindow($from, $to),
            'refunds' => $this->refunds($from, $to),
        ];
    }

    /** @return array{by_cause: array<string, int>, provider_billed: int, most_in_one_workspace: int} */
    private function refunds(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $refunds = AiCreditTransaction::query()
            ->where('type', AiCreditType::Refund)
            ->whereBetween('created_at', [$from, $to])
            ->get(['workspace_id', 'metadata']);

        $byCause = $refunds
            ->countBy(static fn (AiCreditTransaction $refund): string => is_string($refund->metadata['cause'] ?? null) ? $refund->metadata['cause'] : 'unrecorded')
            ->all();

        return [
            'by_cause' => $byCause,
            'provider_billed' => array_sum(array_filter(
                $byCause,
                static fn (string $cause): bool => RefundCause::tryFrom($cause)?->providerBilled() === true,
                ARRAY_FILTER_USE_KEY,
            )),
            'most_in_one_workspace' => (int) $refunds->countBy('workspace_id')->max(),
        ];
    }

    /** @return Builder<AgentConversationMessage> */
    private function assistantTurns(CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return AgentConversationMessage::query()
            ->where('role', 'assistant')
            ->whereBetween('created_at', [$from, $to]);
    }

    private function timeouts(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $pattern = TurnNotes::timeout('%').'%';

        return $this->assistantTurns($from, $to)->errored()
            ->where(fn (Builder $query): Builder => $query
                ->where('content', 'like', $pattern)
                ->orWhere('meta->failure->class', TimeoutExceededException::class))
            ->count();
    }

    /**
     * @return array{blank_replies: int, tool_calls: int, tool_errors: array<string, int>, reference_errors: int, cache: array<string, array{followups: int, zero_read: int, read_share: float}>, top_workspaces: list<array{workspace_id: string, turns: int, prompt_tokens: int}>}
     */
    private function scanTurns(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $blankNotes = array_values(TurnNotes::blankReply());
        $blank = 0;
        $toolCalls = 0;
        $toolErrors = [];
        $referenceErrors = 0;
        $cache = [];
        $workspaces = [];
        $previous = [];

        foreach ($this->scannedRows($from, $to) as $row) {
            $record = (array) $row;
            $message = StoredMessage::fromArray($record);
            $promptTokens = $this->promptTokens($message);

            if (is_string($record['workspace_id'])) {
                $workspaceId = $record['workspace_id'];
                $workspaces[$workspaceId] ??= ['workspace_id' => $workspaceId, 'turns' => 0, 'prompt_tokens' => 0];
                $workspaces[$workspaceId]['turns']++;
                $workspaces[$workspaceId]['prompt_tokens'] += $promptTokens;
            }

            if (($message->meta['error'] ?? false) === true) {
                continue;
            }

            $calls = count($message->toolCalls());
            $blank += $calls === 0 && (trim($message->content) === '' || in_array($message->content, $blankNotes, true)) ? 1 : 0;
            $toolCalls += $calls;

            foreach ($this->failedToolResults($message) as $name => $failure) {
                $toolErrors[$name] = ($toolErrors[$name] ?? 0) + $failure['errors'];
                $referenceErrors += $failure['references'];
            }

            $this->trackFollowUp($cache, $previous, (string) $record['conversation_id'], $message, $promptTokens);
        }

        usort($workspaces, static fn (array $a, array $b): int => $b['prompt_tokens'] <=> $a['prompt_tokens']);
        ksort($toolErrors);

        return [
            'blank_replies' => $blank,
            'tool_calls' => $toolCalls,
            'tool_errors' => $toolErrors,
            'reference_errors' => $referenceErrors,
            'cache' => array_map(static fn (array $stats): array => [
                'followups' => $stats['followups'],
                'zero_read' => $stats['zero_read'],
                'read_share' => $stats['prompt'] > 0 ? round($stats['read'] / $stats['prompt'], 3) : 0.0,
            ], $cache),
            'top_workspaces' => array_slice($workspaces, 0, self::TOP_WORKSPACES),
        ];
    }

    /** @return LazyCollection<int, stdClass> */
    private function scannedRows(CarbonImmutable $from, CarbonImmutable $to): LazyCollection
    {
        return $this->assistantTurns($from, $to)
            ->select(['id', 'conversation_id', 'content', 'steps', 'usage', 'meta', 'created_at'])
            ->addSelect(['workspace_id' => AgentConversation::query()->select('workspace_id')->whereColumn('id', 'agent_conversation_messages.conversation_id')])
            ->toBase()
            ->lazyById(500);
    }

    private function promptTokens(StoredMessage $message): int
    {
        return max(
            (int) ($message->usage['input_tokens'] ?? 0),
            (int) ($message->usage['cache_read_input_tokens'] ?? 0) + (int) ($message->usage['cache_write_input_tokens'] ?? 0),
        );
    }

    /** @return array<string, array{errors: int, references: int}> */
    private function failedToolResults(StoredMessage $message): array
    {
        $failures = [];

        foreach ($message->toolResults() as $result) {
            $raw = $result['result'] ?? null;
            $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
            $reported = is_array($decoded) && array_key_exists('error', $decoded);

            if (! $reported && ($result['failed'] ?? false) !== true) {
                continue;
            }

            $name = (string) ($result['name'] ?? 'unknown');
            $failures[$name] ??= ['errors' => 0, 'references' => 0];
            $failures[$name]['errors']++;
            $failures[$name]['references'] += str_contains(is_string($raw) ? $raw : (json_encode($raw) ?: ''), 'Step reference') ? 1 : 0;
        }

        return $failures;
    }

    /**
     * @param  array<string, array{followups: int, zero_read: int, read: int, prompt: int}>  $cache
     * @param  array<string, array{model: string, at: CarbonInterface}>  $previous
     */
    private function trackFollowUp(array &$cache, array &$previous, string $conversationId, StoredMessage $message, int $promptTokens): void
    {
        $model = $message->meta['model'] ?? null;

        if (! is_string($model)) {
            return;
        }

        if (! $message->createdAt instanceof CarbonInterface) {
            return;
        }

        $createdAt = Date::parse($message->createdAt->toDateTimeString());
        $startedAt = $createdAt->subMilliseconds((int) ($message->meta['duration_ms'] ?? 0));
        $last = $previous[$conversationId] ?? null;
        $previous[$conversationId] = ['model' => $model, 'at' => $createdAt];

        if ($last === null || $last['model'] !== $model || $startedAt->getTimestamp() - $last['at']->getTimestamp() >= self::FOLLOW_UP_SECONDS) {
            return;
        }

        if (! isset($message->usage['cache_read_input_tokens'])) {
            return;
        }

        $provider = (string) ($message->meta['provider'] ?? 'unknown');
        $read = (int) $message->usage['cache_read_input_tokens'];

        $cache[$provider] ??= ['followups' => 0, 'zero_read' => 0, 'read' => 0, 'prompt' => 0];
        $cache[$provider]['followups']++;
        $cache[$provider]['zero_read'] += $read === 0 ? 1 : 0;
        $cache[$provider]['read'] += $read;
        $cache[$provider]['prompt'] += $promptTokens;
    }

    /** @return array<string, int> */
    private function proposals(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return PendingAction::query()
            ->withoutGlobalScopes()
            ->whereBetween('created_at', [$from, $to])
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->orderBy('status')
            ->pluck('total', 'status')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /** @return array{setup_conversations: int, setup_conversations_typed: int} */
    private function setupConversations(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $setup = AgentConversation::query()->setup()->whereBetween('created_at', [$from, $to]);

        return [
            'setup_conversations' => $setup->clone()->count(),
            'setup_conversations_typed' => $setup->clone()
                ->whereIn('id', AgentConversationMessage::query()->typed()->select('conversation_id'))
                ->count(),
        ];
    }
}
