<?php

declare(strict_types=1);

namespace Relaticle\Chat\Jobs;

use App\Models\Team;
use App\Models\User;
use App\Services\WorkspaceActivationFacts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Agents\WelcomeComposer;
use Relaticle\Chat\Services\TipTapDocumentParser;
use Relaticle\Chat\Support\TitleSanitizer;
use Throwable;

/**
 * Seeds Rela's proactive welcome conversation for a brand-new personal
 * workspace. Generation failures fall back to templated copy: this job must
 * never surface an error for a prompt problem, and it never touches the
 * team's credit balance (it bypasses ChatController's reservation path
 * entirely).
 */
final class SendWelcomeMessage implements ShouldQueue
{
    use Queueable;

    public function __construct(public Team $team) {}

    public function handle(): void
    {
        $alreadyWelcomed = DB::table('agent_conversations')
            ->where('team_id', $this->team->getKey())
            ->exists();

        if ($alreadyWelcomed) {
            return;
        }

        $owner = $this->team->owner;

        if (! $owner instanceof User) {
            return;
        }

        $text = $this->compose($owner);
        $document = resolve(TipTapDocumentParser::class)->buildFromText($text, [], $this->team);
        $now = now();
        $conversationId = (string) Str::uuid7();

        DB::transaction(function () use ($conversationId, $owner, $text, $document, $now): void {
            DB::table('agent_conversations')->insert([
                'id' => $conversationId,
                'participant_type' => $owner->getMorphClass(),
                'participant_id' => (string) $owner->getKey(),
                'team_id' => $this->team->getKey(),
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
    }

    private function compose(User $owner): string
    {
        $firstName = explode(' ', $owner->name)[0];

        try {
            $response = (new WelcomeComposer)->prompt($this->workspaceBlock($firstName));

            if ($response instanceof StructuredAgentResponse) {
                $message = $response->structured['message'] ?? null;

                if (is_string($message) && trim($message) !== '') {
                    return trim($message);
                }
            }
        } catch (Throwable) {
            // Fall through to the template.
        }

        return __('chat-welcome.fallback', ['name' => $firstName]);
    }

    private function workspaceBlock(string $firstName): string
    {
        $useCase = $this->team->onboarding_use_case?->getLabel() ?? 'general CRM work';
        $sampleCount = resolve(WorkspaceActivationFacts::class)->sampleRecordCount($this->team);

        return <<<TEXT
        <workspace>
        Owner first name: {$firstName}
        Chosen use case: {$useCase}
        Sample records seeded: {$sampleCount} across companies, people, opportunities, tasks and notes
        </workspace>
        TEXT;
    }
}
