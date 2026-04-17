<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\AiSummary;
use App\Models\User;
use Filament\Facades\Filament;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Facades\Prism;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\EmailThread;
use RuntimeException;

final readonly class EmailThreadSummaryService
{
    /**
     * Get or generate an AI summary for an email thread.
     * Only includes email bodies the viewer has access to.
     */
    public function getSummary(EmailThread $thread, User $viewer, bool $regenerate = false): AiSummary
    {
        if (! $regenerate) {
            $cached = $thread->aiSummary;
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->generateAndCache($thread, $viewer);
    }

    private function generateAndCache(EmailThread $thread, User $viewer): AiSummary
    {
        $emails = $thread->emails()
            ->with(['participants', 'body', 'labels'])
            ->oldest('sent_at')
            ->get();

        $lines = [];
        $lines[] = "Email thread: \"{$thread->subject}\"";
        $lines[] = "{$thread->email_count} emails, {$thread->participant_count} participants";
        $lines[] = 'Date range: '.($thread->first_email_at?->toDateString() ?? '—').' — '.($thread->last_email_at?->toDateString() ?? '—');
        $lines[] = '';

        foreach ($emails as $index => $email) {
            $n = $index + 1;
            $firstFrom = $email->from->first();
            $from = $firstFrom->name ?? $firstFrom->email_address ?? 'Unknown';
            $date = $email->sent_at?->toDateTimeString() ?? '—';
            $dir = $email->direction->getLabel();

            $lines[] = "--- Email {$n} ({$dir}) ---";
            $lines[] = "From: {$from}  |  Date: {$date}";

            $isOwner = $email->user_id === $viewer->getKey();

            if ($isOwner || $email->privacy_tier === EmailPrivacyTier::FULL) {
                $body = $email->body->body_text ?? $email->snippet ?? '(no body)';
                $lines[] = 'Body: '.mb_substr($body, 0, 500);
            } elseif ($email->privacy_tier === EmailPrivacyTier::SUBJECT) {
                $lines[] = "Subject: {$email->subject}  (body hidden)";
            } else {
                $lines[] = '(metadata only)';
            }

            $aiLabels = $email->labels->where('source', 'ai')->pluck('label')->implode(', ');
            if (filled($aiLabels)) {
                $lines[] = "Labels: {$aiLabels}";
            }

            $lines[] = '';
        }

        $response = Prism::text()
            ->using(Provider::Anthropic, config('services.anthropic.summary_model'))
            ->withSystemPrompt($this->systemPrompt())
            ->withPrompt(implode("\n", $lines))
            ->generate();

        $teamId = Filament::getTenant()?->getKey();
        throw_if($teamId === null, RuntimeException::class, 'No team context available for AI thread summary');

        $thread->aiSummary()->delete();

        return AiSummary::query()->create([
            'team_id' => $teamId,
            'summarizable_type' => $thread->getMorphClass(),
            'summarizable_id' => $thread->getKey(),
            'summary' => $response->text,
            'model_used' => config('services.anthropic.summary_model'),
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
        ]);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a CRM assistant summarising email threads for sales and account management professionals.

Rules:
- 2-4 sentences maximum
- Identify the main topic, key decisions, next steps, and any urgency
- Mention participants by role (e.g., "prospect", "account manager") not by name unless highly relevant
- Never reproduce verbatim content from the emails
- Write in flowing professional prose, no bullet points
PROMPT;
    }
}
