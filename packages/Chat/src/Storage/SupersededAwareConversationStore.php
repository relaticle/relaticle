<?php

declare(strict_types=1);

namespace Relaticle\Chat\Storage;

use Illuminate\Support\Collection;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Relaticle\Chat\Support\AssistantText;
use stdClass;

/**
 * Conversation store that hides superseded turns from the agent's history.
 *
 * Regenerate/edit mark replaced turns with superseded_at (see
 * ChatController::supersedeMessages). Without this filter the model keeps
 * "remembering" turns the user replaced — answering "I already proposed that"
 * against a transcript the user can no longer see.
 */
final class SupersededAwareConversationStore extends DatabaseConversationStore
{
    /**
     * Collapse a fully-repeated combined assistant text before persisting.
     *
     * laravel/ai concatenates the model's text deltas across every agent step,
     * so a model that echoes the same acknowledgment in both the tool-call step
     * and the post-tool-result step yields that text repeated back-to-back. We
     * store the single copy instead of the duplicate.
     */
    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        $response->text = AssistantText::collapseRepeated($response->text);

        return parent::storeAssistantMessage($conversationId, $userId, $prompt, $response);
    }

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return $this->table($this->messagesTable())
            ->where('conversation_id', $conversationId)
            ->whereNull('superseded_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(fn (stdClass $record): array => $this->mapRecordToMessages($record));
    }

    /**
     * @return list<Message>
     */
    private function mapRecordToMessages(stdClass $record): array
    {
        $toolCalls = collect((array) json_decode((string) $record->tool_calls, true))->values();
        $toolResults = collect((array) json_decode((string) $record->tool_results, true))->values();

        if ($record->role === 'user') {
            return [new Message('user', $record->content)];
        }

        if ($toolCalls->isNotEmpty()) {
            $messages = [];

            $messages[] = new AssistantMessage(
                $record->content ?: '',
                $toolCalls->map(fn (array $toolCall): ToolCall => new ToolCall(
                    id: $toolCall['id'],
                    name: $toolCall['name'],
                    arguments: $toolCall['arguments'],
                    resultId: $toolCall['result_id'] ?? null,
                    reasoningId: $toolCall['reasoning_id'] ?? null,
                    reasoningSummary: $toolCall['reasoning_summary'] ?? null,
                ))
            );

            if ($toolResults->isNotEmpty()) {
                $messages[] = new ToolResultMessage(
                    $toolResults->map(fn (array $toolResult): ToolResult => new ToolResult(
                        id: $toolResult['id'],
                        name: $toolResult['name'],
                        arguments: $toolResult['arguments'],
                        result: $toolResult['result'],
                        resultId: $toolResult['result_id'] ?? null,
                    ))
                );
            }

            return $messages;
        }

        return [new AssistantMessage($record->content)];
    }
}
