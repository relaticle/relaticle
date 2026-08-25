<?php

declare(strict_types=1);

namespace Relaticle\Chat\Actions;

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Services\TipTapDocumentParser;
use Relaticle\Chat\Support\TitleSanitizer;
use Relaticle\Chat\Support\WelcomeCopy;

/**
 * Writes Rela's welcome conversation with templated copy inside the request
 * that creates the workspace. The dashboard renders this row on first paint, so
 * it cannot wait for the queue; SendWelcomeMessage only improves the wording.
 */
final readonly class SeedWelcomeConversation
{
    public function __construct(
        private TipTapDocumentParser $parser,
        private WelcomeCopy $copy,
    ) {}

    public function execute(Team $team): ?string
    {
        $owner = $team->owner;

        if (! $owner instanceof User) {
            return null;
        }

        $existing = DB::table('agent_conversations')
            ->where('team_id', $team->getKey())
            ->value('id');

        if ($existing !== null) {
            return (string) $existing;
        }

        $text = $this->copy->templated($owner);
        $document = $this->parser->buildFromText($text, [], $team);
        $now = now();
        $conversationId = (string) Str::uuid7();

        DB::transaction(function () use ($conversationId, $team, $owner, $text, $document, $now): void {
            DB::table('agent_conversations')->insert([
                'id' => $conversationId,
                'participant_type' => $owner->getMorphClass(),
                'participant_id' => (string) $owner->getKey(),
                'team_id' => $team->getKey(),
                'title' => TitleSanitizer::clean(__('chat-welcome.title')),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('agent_conversation_messages')->insert([
                'id' => (string) Str::uuid7(),
                'conversation_id' => $conversationId,
                'participant_type' => $owner->getMorphClass(),
                'participant_id' => (string) $owner->getKey(),
                'agent' => CrmAssistant::class,
                'role' => 'assistant',
                'content' => $text,
                'attachments' => '[]',
                'tool_calls' => '[]',
                'tool_results' => '[]',
                'usage' => '[]',
                'meta' => json_encode(['welcome' => true], JSON_THROW_ON_ERROR),
                'document' => json_encode($document, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return $conversationId;
    }
}
