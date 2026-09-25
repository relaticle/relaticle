<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string MESSAGES = 'agent_conversation_messages';

    // Keys laravel/ai 1.0 DatabaseConversationStore::toolCallsFor() no longer stores.
    private const array DROPPED_CALL_KEYS = ['reasoning_id', 'reasoning_summary', 'reasoning_encrypted_content'];

    private const array MOVED_META_KEYS = ['reasoning', 'provider_steps', 'provider_content_blocks'];

    public function up(): void
    {
        DB::table(self::MESSAGES)
            ->select('conversation_id')
            ->distinct()
            ->eachById(function (object $row): void {
                $this->backfill((string) $row->conversation_id);
            }, 100, 'conversation_id');

        DB::statement("SET LOCAL lock_timeout = '5s'");

        DB::statement('ALTER TABLE agent_conversation_messages ALTER COLUMN steps DROP DEFAULT');

        Schema::table(self::MESSAGES, function (Blueprint $table): void {
            $table->dropColumn(['tool_calls', 'tool_results', 'approval_state']);
        });
    }

    private function backfill(string $conversationId): void
    {
        $rows = DB::table(self::MESSAGES)
            ->where('conversation_id', $conversationId)
            ->where('role', 'assistant')
            ->orderBy('id')
            ->get(['id', 'content', 'tool_calls', 'tool_results', 'meta']);

        // 0.11 recorded a result on the row of the request that produced it, which may be a later row than its call.
        /** @var Collection<string, array<string, mixed>> $results */
        $results = $rows
            ->flatMap(fn (object $row): array => $this->decoded($row->tool_results))
            ->filter(fn (mixed $result): bool => is_array($result) && is_string($result['id'] ?? null))
            ->keyBy('id');

        foreach ($rows as $row) {
            $calls = collect($this->decoded($row->tool_calls))
                ->filter(fn (mixed $call): bool => is_array($call) && $results->has((string) ($call['id'] ?? '')))
                ->map(fn (array $call): array => $this->answered($call, $results[$call['id']]))
                ->values()
                ->all();

            $meta = $this->decoded($row->meta);
            $content = (string) $row->content;
            $reasoning = (string) ($meta['reasoning'] ?? '');

            $steps = $calls !== [] && $content !== ''
                ? [$this->step('', $calls, ''), $this->step($content, [], $reasoning)]
                : [$this->step($content, $calls, $reasoning)];

            $update = ['steps' => json_encode($steps, JSON_THROW_ON_ERROR)];

            if (Arr::hasAny($meta, self::MOVED_META_KEYS)) {
                $update['meta'] = json_encode((object) Arr::except($meta, self::MOVED_META_KEYS), JSON_THROW_ON_ERROR);
            }

            DB::table(self::MESSAGES)->where('id', $row->id)->update($update);
        }
    }

    /**
     * @param  array<string, mixed>  $call
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function answered(array $call, array $result): array
    {
        $stored = Arr::except($call, self::DROPPED_CALL_KEYS);

        if (($stored['thought_signature'] ?? null) === null) {
            unset($stored['thought_signature']);
        }

        return [
            ...$stored,
            'result' => $result['result'] ?? null,
            ...array_filter(Arr::only($result, ['denied', 'failed'])),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $calls
     * @return array{content: string, tool_calls: list<array<string, mixed>>, reasoning: string, replay_blocks: array{}, provider_tool_calls: array{}}
     */
    private function step(string $content, array $calls, string $reasoning): array
    {
        return [
            'content' => $content,
            'tool_calls' => $calls,
            'reasoning' => $reasoning,
            'replay_blocks' => [],
            'provider_tool_calls' => [],
        ];
    }

    /** @return array<array-key, mixed> */
    private function decoded(mixed $json): array
    {
        return is_array($decoded = json_decode((string) $json, true)) ? $decoded : [];
    }
};
