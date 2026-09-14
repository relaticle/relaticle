<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Services\TipTapDocumentParser;
use Relaticle\Chat\Support\ChatAttachment;

final readonly class StoreImportHandoff
{
    public function __construct(
        private TipTapDocumentParser $documents,
        private ConsumeChatAttachment $consume,
        private ListConversationMessages $messages,
    ) {}

    /**
     * @param  array<string, mixed>  $document
     * @return array{user_message_id: string, assistant: array<string, mixed>}
     */
    public function execute(User $user, Workspace $workspace, string $conversationId, ChatAttachment $attachment, string $text, array $document): array
    {
        $userMessageId = (string) Str::uuid7();
        $assistantMessageId = (string) Str::uuid7();
        $reply = $this->reply($attachment);
        $now = now();

        DB::transaction(function () use ($user, $workspace, $conversationId, $attachment, $text, $document, $userMessageId, $assistantMessageId, $reply, $now): void {
            DB::table('agent_conversation_messages')->insert([
                [
                    'id' => $userMessageId,
                    'conversation_id' => $conversationId,
                    'participant_type' => $user->getMorphClass(),
                    'participant_id' => (string) $user->getKey(),
                    'agent' => CrmAssistant::class,
                    'role' => 'user',
                    'content' => $text !== '' ? $text : __('Attached :name', ['name' => $attachment->name()]),
                    'attachments' => '[]',
                    'tool_calls' => '[]',
                    'tool_results' => '[]',
                    'usage' => '[]',
                    'meta' => json_encode(['attachment' => $attachment->meta()], JSON_THROW_ON_ERROR),
                    'document' => json_encode($document, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                [
                    'id' => $assistantMessageId,
                    'conversation_id' => $conversationId,
                    'participant_type' => $user->getMorphClass(),
                    'participant_id' => (string) $user->getKey(),
                    'agent' => CrmAssistant::class,
                    'role' => 'assistant',
                    'content' => $reply,
                    'attachments' => '[]',
                    'tool_calls' => '[]',
                    'tool_results' => '[]',
                    'usage' => '[]',
                    'meta' => json_encode(['kind' => 'import_handoff'], JSON_THROW_ON_ERROR),
                    'document' => json_encode($this->documents->buildFromText($reply, [], $workspace), JSON_THROW_ON_ERROR),
                    'created_at' => $now->addSecond(),
                    'updated_at' => $now->addSecond(),
                ],
            ]);

            DB::table('agent_conversations')->where('id', $conversationId)->update(['updated_at' => $now]);

            $this->consume->execute($attachment, $conversationId);
        });

        $assistant = collect($this->messages->execute($user, $conversationId, null, 2))
            ->firstWhere('id', $assistantMessageId);

        return ['user_message_id' => $userMessageId, 'assistant' => $assistant ?? []];
    }

    private function reply(ChatAttachment $attachment): string
    {
        $people = route('chat.attachments.import', ['attachment' => $attachment->id(), 'entity' => 'people']);
        $companies = route('chat.attachments.import', ['attachment' => $attachment->id(), 'entity' => 'company']);

        return __("That's :count rows. The import wizard handles files this size, with your columns already mapped.", ['count' => $attachment->rowCount()])
            ."\n\n[".__('Import as people').']('.$people.') · ['.__('Import as companies').']('.$companies.')';
    }
}
