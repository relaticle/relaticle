<?php

declare(strict_types=1);

namespace Relaticle\Chat\Storage;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Storage\DatabaseConversationStore;
use Relaticle\Chat\Support\AssistantText;
use Relaticle\Chat\Support\FirstChatUsageTagger;
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
     * The conversation tables carry a polymorphic participant_type +
     * participant_id pair; laravel/ai 0.6 still reads and writes the user_id
     * column the migration renamed, so every inherited write is reimplemented
     * here against the participant pair. Deleting these four overrides is part
     * of the laravel/ai 0.10 upgrade, whose own store writes the pair natively.
     */
    public function latestConversationId(string|int $userId): ?string
    {
        return $this->table($this->conversationsTable())
            ->where('participant_type', $this->participantType())
            ->where('participant_id', $userId)
            ->latest('updated_at')
            ->first()?->id;
    }

    public function storeConversation(string|int|null $userId, string $title): string
    {
        $conversationId = (string) Str::uuid7();

        $this->table($this->conversationsTable())->insert([
            'id' => $conversationId,
            'participant_type' => $userId === null ? null : $this->participantType(),
            'participant_id' => $userId,
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $conversationId;
    }

    /**
     * Tag the "has-ai-usage" marketing segment on the subscriber's first
     * chat message. This is the real write path for user turns (see
     * RememberConversation middleware in laravel/ai), unlike the
     * AgentConversationMessage Eloquent model, which never receives writes.
     */
    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
    {
        $messageId = (string) Str::uuid7();

        $this->table($this->messagesTable())->insert([
            ...$this->messageRow($conversationId, $userId, $prompt),
            'id' => $messageId,
            'role' => 'user',
            'content' => $prompt->prompt,
            'attachments' => $prompt->attachments->toJson(),
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '[]',
            'meta' => '[]',
        ]);

        FirstChatUsageTagger::tagIfFirstMessage($messageId);

        return $messageId;
    }

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
        $messageId = (string) Str::uuid7();

        $this->table($this->messagesTable())->insert([
            ...$this->messageRow($conversationId, $userId, $prompt),
            'id' => $messageId,
            'role' => 'assistant',
            'content' => AssistantText::collapseRepeated($response->text),
            'attachments' => '[]',
            'tool_calls' => json_encode($response->toolCalls->values()),
            'tool_results' => json_encode($response->toolResults->values()),
            'usage' => json_encode($response->usage),
            'meta' => json_encode($response->meta),
        ]);

        return $messageId;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageRow(string $conversationId, string|int|null $userId, AgentPrompt $prompt): array
    {
        return [
            'conversation_id' => $conversationId,
            'participant_type' => $userId === null ? null : $this->participantType(),
            'participant_id' => $userId,
            'agent' => $prompt->agent::class,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function participantType(): string
    {
        return (new User)->getMorphClass();
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
