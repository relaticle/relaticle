<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Events\NextStepsSuggested;
use Relaticle\Chat\Support\ChatTelemetry;
use Throwable;

final readonly class RecordNextSteps
{
    /**
     * @param  list<array<string, mixed>>  $steps
     */
    public function execute(string $conversationId, string $messageId, array $steps): bool
    {
        $row = DB::table('agent_conversation_messages')
            ->where('id', $messageId)
            ->first(['meta']);

        // Regenerating or editing a message deletes the row the steps were drafted
        // for. Writing them to whatever assistant row is latest instead would
        // attach them to a different answer.
        if ($row === null) {
            return false;
        }

        $existing = json_decode((string) $row->meta, associative: true);
        $meta = is_array($existing) ? $existing : [];
        $meta['next_steps'] = $steps;

        DB::table('agent_conversation_messages')
            ->where('id', $messageId)
            ->update(['meta' => json_encode($meta, JSON_THROW_ON_ERROR)]);

        $this->broadcast($conversationId, $steps);

        return true;
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function broadcast(string $conversationId, array $steps): void
    {
        // Already persisted; a Reverb hiccup must not fail the caller over it. The
        // client's turn-end reconcile pulls what a dropped broadcast missed.
        try {
            broadcast(new NextStepsSuggested(conversationId: $conversationId, steps: $steps));
        } catch (Throwable $e) {
            ChatTelemetry::breadcrumb('broadcast.dropped', [
                'event' => NextStepsSuggested::class,
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
