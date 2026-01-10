<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AiSummary;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

final readonly class RecordSummaryService
{
    private const string MODEL = 'claude-3-5-haiku-latest';

    public function __construct(
        private RecordContextBuilder $contextBuilder
    ) {}

    /**
     * Get or generate an AI summary for a record.
     */
    public function getSummary(Model $record, bool $regenerate = false): AiSummary
    {
        if (! $regenerate && method_exists($record, 'aiSummary')) {
            /** @var AiSummary|null $cached */
            $cached = $record->aiSummary; // @phpstan-ignore property.notFound
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->generateAndCacheSummary($record);
    }

    private function generateAndCacheSummary(Model $record): AiSummary
    {
        $context = $this->contextBuilder->buildContext($record);
        $prompt = $this->formatPrompt($context);

        $response = Prism::text()
            ->using(Provider::Anthropic, self::MODEL)
            ->withSystemPrompt($this->getSystemPrompt())
            ->withPrompt($prompt)
            ->generate();

        return $this->cacheSummary($record, $response->text, $response->usage);
    }

    private function cacheSummary(Model $record, string $summary, Usage $usage): AiSummary
    {
        $teamId = Filament::getTenant()?->getKey();

        if ($teamId === null) {
            throw new RuntimeException('No team context available for caching AI summary');
        }

        if (method_exists($record, 'aiSummary')) {
            $record->aiSummary()->delete();
        }

        return AiSummary::query()->create([
            'team_id' => $teamId,
            'summarizable_type' => $record->getMorphClass(),
            'summarizable_id' => $record->getKey(),
            'summary' => $summary,
            'model_used' => self::MODEL,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
        ]);
    }

    private function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a CRM assistant that generates concise, actionable summaries of business records.

Your summaries should:
- Be 2-3 sentences maximum
- Highlight the most important business context
- Include relevant metrics (deal size, last contact date, open tasks) when available
- Suggest urgency or opportunity when applicable
- Use professional, direct language
- Focus on actionable insights for a sales or account management professional

Do not use bullet points or formatting. Write in flowing prose that is easy to scan quickly.
PROMPT;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function formatPrompt(array $context): string
    {
        $parts = collect([
            "Generate a brief CRM summary for this {$context['entity_type']}:",
            '',
            "**{$context['name']}**",
        ]);

        $this->addBasicInfo($parts, $context);
        $this->addRelationships($parts, $context);
        $this->addOpportunities($parts, $context);
        $this->addNotes($parts, $context);
        $this->addTasks($parts, $context);
        $this->addTimestamps($parts, $context);

        return $parts->implode("\n");
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $parts
     * @param  array<string, mixed>  $context
     */
    private function addBasicInfo(\Illuminate\Support\Collection $parts, array $context): void
    {
        if (empty($context['basic_info'])) {
            return;
        }

        $parts->push('', 'Basic Information:');
        foreach ($context['basic_info'] as $key => $value) {
            $parts->push("- {$this->formatLabel($key)}: {$value}");
        }

        if (filled($context['company'] ?? null)) {
            $parts->push("- Company: {$context['company']}");
        }

        if (filled($context['contact'] ?? null)) {
            $parts->push("- Contact: {$context['contact']}");
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $parts
     * @param  array<string, mixed>  $context
     */
    private function addRelationships(\Illuminate\Support\Collection $parts, array $context): void
    {
        if (empty($context['relationships'])) {
            return;
        }

        $parts->push('', 'Relationships:');
        foreach ($context['relationships'] as $key => $value) {
            $parts->push("- {$this->formatLabel($key)}: {$value}");
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $parts
     * @param  array<string, mixed>  $context
     */
    private function addOpportunities(\Illuminate\Support\Collection $parts, array $context): void
    {
        $opportunities = Arr::get($context, 'opportunities.items', []);
        if (empty($opportunities)) {
            return;
        }

        $total = Arr::get($context, 'opportunities.total', count($opportunities));
        $showing = Arr::get($context, 'opportunities.showing', count($opportunities));

        $header = $total > $showing
            ? "Opportunities (showing {$showing} of {$total}):"
            : 'Opportunities:';

        $parts->push('', $header);
        foreach ($opportunities as $opp) {
            $stage = $opp['stage'] ?? 'Unknown stage';
            $amount = $opp['amount'] ?? 'No amount';
            $parts->push("- {$stage}: {$amount}");
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $parts
     * @param  array<string, mixed>  $context
     */
    private function addNotes(\Illuminate\Support\Collection $parts, array $context): void
    {
        $notes = Arr::get($context, 'notes.items', []);
        if (empty($notes)) {
            return;
        }

        $total = Arr::get($context, 'notes.total', count($notes));
        $showing = Arr::get($context, 'notes.showing', count($notes));

        $header = $total > $showing
            ? "Recent Notes (showing {$showing} of {$total}):"
            : 'Recent Notes:';

        $parts->push('', $header);
        foreach (array_slice($notes, 0, 5) as $note) {
            $title = $note['title'] ?? 'Untitled';
            $content = $note['content'] ?? '';
            $created = $note['created'] ?? '';
            $parts->push("- [{$created}] {$title}: {$content}");
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $parts
     * @param  array<string, mixed>  $context
     */
    private function addTasks(\Illuminate\Support\Collection $parts, array $context): void
    {
        $tasks = Arr::get($context, 'tasks.items', []);
        if (empty($tasks)) {
            return;
        }

        $total = Arr::get($context, 'tasks.total', count($tasks));
        $showing = Arr::get($context, 'tasks.showing', count($tasks));

        $header = $total > $showing
            ? "Tasks (showing {$showing} of {$total}):"
            : 'Tasks:';

        $parts->push('', $header);
        foreach ($tasks as $task) {
            $parts->push($this->formatTaskLine($task));
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function formatTaskLine(array $task): string
    {
        $title = $task['title'] ?? 'Untitled';
        $status = $task['status'] ?? 'Unknown';
        $line = "- {$title} ({$status})";

        if (filled($task['priority'] ?? null)) {
            $line .= " - Priority: {$task['priority']}";
        }

        if (filled($task['due_date'] ?? null)) {
            $line .= " - Due: {$task['due_date']}";
        }

        return $line;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $parts
     * @param  array<string, mixed>  $context
     */
    private function addTimestamps(\Illuminate\Support\Collection $parts, array $context): void
    {
        $parts->push(
            '',
            "Last updated: {$context['last_updated']}",
            "Created: {$context['created']}"
        );
    }

    private function formatLabel(string $key): string
    {
        return str($key)->replace('_', ' ')->title()->toString();
    }
}
