<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Features\SetupConversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Models\AgentConversation;
use Relaticle\Chat\Services\TipTapDocumentParser;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;
use Relaticle\Chat\Support\SetupOpener;

final readonly class CreateSetupConversation
{
    public function __construct(
        private SetupOpener $opener,
        private TipTapDocumentParser $documents,
    ) {}

    public function execute(Workspace $workspace): ?string
    {
        if (! Feature::active(SetupConversation::class)) {
            return null;
        }

        if (! $workspace->isPersonalWorkspace()) {
            return null;
        }

        $owner = $workspace->owner;

        if (! $owner instanceof User) {
            return null;
        }

        if ($workspace->setupConversation()->exists()) {
            return null;
        }

        $conversationId = (string) Str::uuid7();
        $content = $this->opener->compose($workspace);
        $now = now();

        try {
            DB::transaction(function () use ($workspace, $owner, $conversationId, $content, $now): void {
                DB::table('agent_conversations')->insert([
                    'id' => $conversationId,
                    'participant_type' => $owner->getMorphClass(),
                    'participant_id' => (string) $owner->getKey(),
                    'workspace_id' => $workspace->getKey(),
                    'title' => __('onboarding/setup.title'),
                    'purpose' => AgentConversation::PURPOSE_SETUP,
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
                    'content' => $content,
                    'attachments' => '[]',
                    'tool_calls' => '[]',
                    'tool_results' => '[]',
                    'usage' => '[]',
                    'meta' => json_encode(['kind' => SupersededAwareConversationStore::SETUP_OPENER_KIND], JSON_THROW_ON_ERROR),
                    'document' => json_encode($this->documents->buildFromText($content, [], $workspace), JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            $existingId = $workspace->setupConversation()->value('id');

            return is_string($existingId) ? $existingId : null;
        }

        return $conversationId;
    }
}
