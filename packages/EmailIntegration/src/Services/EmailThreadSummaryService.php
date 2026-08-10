<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\AiSummary;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Agents\ThreadSummarizer;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\EmailThread;
use RuntimeException;

final readonly class EmailThreadSummaryService
{
    public function __construct(
        private PrivacyService $privacy,
    ) {}

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
            ->with(['from', 'participants', 'body', 'labels', 'shares'])
            ->oldest('sent_at')
            ->get();

        $lines = [];
        $lines[] = "Email thread: \"{$thread->subject}\"";
        $lines[] = "{$thread->email_count} emails, {$thread->participant_count} participants";
        $lines[] = 'Date range: '.($thread->first_email_at?->toDateString() ?? '—').' — '.($thread->last_email_at?->toDateString() ?? '—');
        $lines[] = '';

        foreach ($emails as $index => $email) {
            $n = $index + 1;
            // `from` is eager-loaded above to avoid an N+1 across the thread's emails.
            // A malformed/draft message can carry no `from` participant, so the collection
            // may be empty — default rather than dereference a missing row.
            $firstFrom = $email->from->first();
            $from = $firstFrom instanceof EmailParticipant
                ? ($firstFrom->name ?? $firstFrom->email_address ?? 'Unknown')
                : 'Unknown';
            $date = $email->sent_at?->toDateTimeString() ?? '—';
            $dir = $email->direction->getLabel();

            $lines[] = "--- Email {$n} ({$dir}) ---";
            $lines[] = "From: {$from}  |  Date: {$date}";

            // Single source of truth for visibility: PrivacyService::effectiveTier() also
            // honours per-email shares, internal-email and protected-recipient hiding, which
            // a raw privacy_tier read would leak into the AI prompt + cached summary.
            $tier = $this->privacy->effectiveTier($email, $viewer);

            if ($tier === EmailPrivacyTier::FULL) {
                $body = data_get($email, 'body.body_text', $email->snippet ?? '(no body)');
                $lines[] = 'Body: '.mb_substr((string) $body, 0, 500);
            } elseif ($tier === EmailPrivacyTier::SUBJECT) {
                $lines[] = "Subject: {$email->subject}  (body hidden)";
            } elseif ($tier === EmailPrivacyTier::METADATA_ONLY) {
                $lines[] = '(metadata only)';
            } else {
                // null tier — fully hidden from this viewer
                $lines[] = '(restricted)';
            }

            $aiLabels = $email->labels->where('source', 'ai')->pluck('label')->implode(', ');
            if (filled($aiLabels)) {
                $lines[] = "Labels: {$aiLabels}";
            }

            $lines[] = '';
        }

        $provider = (string) config('services.email_summary.provider');
        $model = (string) config('services.email_summary.model');

        $response = (new ThreadSummarizer)->prompt(
            implode("\n", $lines),
            provider: $provider,
            model: $model,
        );

        $teamId = Filament::getTenant()?->getKey();
        throw_if($teamId === null, RuntimeException::class, 'No team context available for AI thread summary');

        $thread->aiSummary()->delete();

        return AiSummary::query()->create([
            'team_id' => $teamId,
            'summarizable_type' => $thread->getMorphClass(),
            'summarizable_id' => $thread->getKey(),
            'summary' => $response->text,
            'model_used' => $model,
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
        ]);
    }
}
