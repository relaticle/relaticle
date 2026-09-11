<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\AiSummary;
use App\Models\User;
use Carbon\CarbonInterface;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Agents\ThreadSummarizer;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailLabel;
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
     * Only includes messages and fields the viewer can see.
     */
    public function getSummary(EmailThread $thread, User $viewer, bool $regenerate = false): AiSummary
    {
        $prompt = $this->buildPrompt($thread, $viewer);
        $inputHash = hash('sha256', $viewer->getKey()."\n".$prompt);

        if (! $regenerate) {
            $cached = $thread->aiSummary()->where('input_hash', $inputHash)->first();
            if ($cached !== null) {
                return $cached;
            }
        }

        return $this->generateAndCache($thread, $prompt, $inputHash);
    }

    private function buildPrompt(EmailThread $thread, User $viewer): string
    {
        $emails = $thread->emails()
            ->with(['from', 'participants', 'body', 'labels', 'shares'])
            ->oldest('sent_at')
            ->get();

        /** @var list<array{email: Email, tier: EmailPrivacyTier}> $entries */
        $entries = [];

        foreach ($emails as $email) {
            // Shares, internal mail, and protected recipients all resolve here.
            // A raw privacy_tier read would leak those rows into the prompt.
            $tier = $this->privacy->effectiveTier($email, $viewer);

            if (! $tier instanceof EmailPrivacyTier) {
                continue;
            }

            $entries[] = ['email' => $email, 'tier' => $tier];
        }

        $lines = $this->headerLines($entries);

        foreach ($entries as $index => $entry) {
            $lines = [...$lines, ...$this->emailLines($index + 1, $entry['email'], $entry['tier'])];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{email: Email, tier: EmailPrivacyTier}>  $entries
     * @return list<string>
     */
    private function headerLines(array $entries): array
    {
        $subject = null;
        $earliest = null;
        $latest = null;
        $participantAddresses = [];

        foreach ($entries as $entry) {
            $email = $entry['email'];

            if ($subject === null && in_array($entry['tier'], [EmailPrivacyTier::SUBJECT, EmailPrivacyTier::FULL], true)) {
                $subject = $email->subject;
            }

            $sentAt = $email->sent_at;

            if ($sentAt instanceof CarbonInterface) {
                if (! $earliest instanceof CarbonInterface || $sentAt->lt($earliest)) {
                    $earliest = $sentAt;
                }

                if (! $latest instanceof CarbonInterface || $sentAt->gt($latest)) {
                    $latest = $sentAt;
                }
            }

            foreach ($email->participants as $participant) {
                $participantAddresses[] = $participant->email_address;
            }
        }

        $lines = [];
        $lines[] = $subject === null ? 'Email thread' : "Email thread: \"{$subject}\"";
        $lines[] = count($entries).' emails, '.count(array_unique($participantAddresses)).' participants';
        $lines[] = 'Date range: '.($earliest?->toDateString() ?? '—').' to '.($latest?->toDateString() ?? '—');
        $lines[] = '';

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function emailLines(int $n, Email $email, EmailPrivacyTier $tier): array
    {
        $firstFrom = $email->from->first();
        $from = $firstFrom instanceof EmailParticipant
            ? ($firstFrom->name ?? $firstFrom->email_address ?? 'Unknown')
            : 'Unknown';
        $date = $email->sent_at?->toDateTimeString() ?? '—';
        $dir = $email->direction->getLabel();

        $lines = [
            "--- Email {$n} ({$dir}) ---",
            "From: {$from}  |  Date: {$date}",
        ];

        if ($tier === EmailPrivacyTier::FULL) {
            $body = data_get($email, 'body.body_text', $email->snippet ?? '(no body)');
            $lines[] = 'Body: '.mb_substr((string) $body, 0, 500);
        } elseif ($tier === EmailPrivacyTier::SUBJECT) {
            $lines[] = "Subject: {$email->subject}  (body hidden)";
        } else {
            $lines[] = '(metadata only)';
        }

        $category = $email->categoryLabel();

        if ($category instanceof EmailLabel) {
            $lines[] = "Labels: {$category->label}";
        }

        $lines[] = '';

        return $lines;
    }

    private function generateAndCache(EmailThread $thread, string $prompt, string $inputHash): AiSummary
    {
        $provider = (string) config('services.email_summary.provider');
        $model = (string) config('services.email_summary.model');

        $response = (new ThreadSummarizer)->prompt(
            $prompt,
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
            'input_hash' => $inputHash,
            'model_used' => $model,
            'prompt_tokens' => $response->usage->promptTokens,
            'completion_tokens' => $response->usage->completionTokens,
        ]);
    }
}
