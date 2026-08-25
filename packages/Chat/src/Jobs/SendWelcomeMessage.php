<?php

declare(strict_types=1);

namespace Relaticle\Chat\Jobs;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Relaticle\Chat\Agents\WelcomeComposer;
use Relaticle\Chat\Services\AiModelResolver;
use Relaticle\Chat\Services\TipTapDocumentParser;
use Relaticle\Chat\Support\WelcomeCopy;
use Throwable;

/**
 * Refines Rela's welcome message with LLM-composed copy once the workspace's
 * templated version already exists (SeedWelcomeConversation writes it
 * synchronously). Generation failures leave the template in place: this job
 * must never surface an error for a prompt problem, and it never touches the
 * team's credit balance (it bypasses ChatController's reservation path
 * entirely).
 */
#[Timeout(60)]
/*
 * The workspace can be deleted between dispatch and execution; a welcome for a
 * workspace that no longer exists is not worth a failed job.
 */
#[DeleteWhenMissingModels]
final class SendWelcomeMessage implements ShouldQueue
{
    use Queueable;

    /**
     * Hard ceiling on the generated message, enforced after generation.
     */
    private const int MAX_LENGTH = 900;

    public function __construct(public Team $team) {}

    public function handle(): void
    {
        $owner = $this->team->owner;

        if (! $owner instanceof User) {
            return;
        }

        $message = DB::table('agent_conversation_messages as m')
            ->join('agent_conversations as c', 'c.id', '=', 'm.conversation_id')
            ->where('c.team_id', $this->team->getKey())
            ->whereRaw("coalesce(m.meta->>'welcome', '') = 'true'")
            ->orderBy('m.id')
            ->first(['m.id', 'm.conversation_id']);

        // SeedWelcomeConversation writes this row synchronously, so a missing
        // one means the workspace was created before that shipped, or the team
        // was deleted. Either way there is nothing to refine.
        if ($message === null) {
            return;
        }

        $text = $this->compose($owner);
        $document = resolve(TipTapDocumentParser::class)->buildFromText($text, [], $this->team);

        // The guard lives inside the statement on purpose. A read followed by a
        // write loses the race when a reply lands between the two, and rewriting
        // a message a turn has already consumed invalidates the prompt cache
        // prefix from that turn on. Zero affected rows is a success: the user
        // beat the job and the templated copy stands.
        DB::table('agent_conversation_messages')
            ->where('id', $message->id)
            ->whereNotExists(fn (Builder $query): Builder => $query
                ->select(DB::raw('1'))
                ->from('agent_conversation_messages as u')
                ->where('u.conversation_id', $message->conversation_id)
                ->where('u.role', 'user'))
            ->update([
                'content' => $text,
                'document' => json_encode($document, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
    }

    private function compose(User $owner): string
    {
        $copy = resolve(WelcomeCopy::class);
        $firstName = $copy->firstName($owner);

        try {
            $response = (new WelcomeComposer)->prompt(
                $this->workspaceBlock($firstName),
                provider: resolve(AiModelResolver::class)->resolve($owner)['provider'],
            );

            if ($response instanceof StructuredAgentResponse) {
                $message = $response->structured['message'] ?? null;

                if (is_string($message) && trim($message) !== '') {
                    // The spec caps this post-generation: the same production data
                    // that motivated the feature shows long replies end conversations,
                    // and MaxTokens is a model-side hint, not a guarantee.
                    return $this->sanitize($message);
                }
            }
        } catch (Throwable) {
            // Fall through to the template.
        }

        return $copy->templated($owner);
    }

    /**
     * The prompt forbids em dashes, but a prompt rule is a request, not a
     * guarantee, and the house style bans them outright. Enforce it here, and
     * apply the spec's length cap, before anything reaches a customer.
     */
    private function sanitize(string $message): string
    {
        $clean = str_replace(["\u{2014}", "\u{2013}"], ', ', trim($message));

        return Str::limit($clean, self::MAX_LENGTH, preserveWords: true);
    }

    private function workspaceBlock(string $firstName): string
    {
        $useCase = $this->team->onboarding_use_case?->getLabel() ?? 'general CRM work';
        $context = implode(', ', $this->team->onboarding_context ?? []);
        $samples = implode("\n", $this->sampleHighlights());
        $todo = implode("\n", $this->unfinishedSteps());

        return <<<TEXT
        <workspace>
        Owner first name: {$firstName}
        Chosen use case: {$useCase}
        Use case details: {$context}
        Sample records seeded (name these, never invent others):
        {$samples}
        Setup steps still outstanding:
        {$todo}
        </workspace>
        TEXT;
    }

    /**
     * A handful of real seeded record names. The prompt asks the model to open
     * on a concrete observation, so a bare count would leave it either vague or
     * inventing records the workspace does not contain.
     *
     * @return list<string>
     */
    private function sampleHighlights(): array
    {
        $lines = [];

        // Tasks label themselves with `title`; every other entity uses `name`.
        $sources = [
            [Opportunity::class, 'deal', 'name'],
            [Company::class, 'company', 'name'],
            [Task::class, 'task', 'title'],
        ];

        foreach ($sources as [$model, $label, $column]) {
            $names = $model::query()
                ->where('team_id', $this->team->getKey())
                ->where('creation_source', CreationSource::SYSTEM)
                ->orderBy('id')
                ->limit(2)
                ->pluck($column)
                ->all();

            foreach ($names as $name) {
                $lines[] = "- {$label}: {$name}";
            }
        }

        return $lines === [] ? ['- none'] : $lines;
    }

    /**
     * The activation steps this workspace has not finished, so the offers the
     * model makes match what is actually left to do.
     *
     * @return list<string>
     */
    private function unfinishedSteps(): array
    {
        $lines = [];

        foreach ($this->team->onboarding()->steps() as $step) {
            if ($step->incomplete()) {
                $lines[] = '- '.__((string) $step->attribute('label_key'));
            }
        }

        return $lines === [] ? ['- none'] : $lines;
    }
}
