<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\AiSummary;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailLabel;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class EmailThreadSummaryService
{
    public function __construct(
        private PrivacyService $privacy,
        private ConversationsQuery $conversations,
    ) {}

    public function cached(User $viewer, EmailConversation $conversation, string $inputHash): ?AiSummary
    {
        return AiSummary::query()->where('workspace_id', $viewer->current_workspace_id)
            ->where('user_id', $viewer->getKey())->where('summarizable_type', $conversation->getMorphClass())
            ->where('summarizable_id', $conversation->getKey())->where('input_hash', $inputHash)->first();
    }

    public function prompt(User $viewer, string $conversationKey): string
    {
        $emails = $this->conversations->messages($viewer, $conversationKey)->load(['from', 'body', 'labels']);

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

        $lines = $this->headerLines($entries, $viewer);

        foreach ($entries as $index => $entry) {
            $lines = [...$lines, ...$this->emailLines($index + 1, $entry['email'], $entry['tier'])];
        }

        return implode("\n", $lines);
    }

    /**
     * @param  list<array{email: Email, tier: EmailPrivacyTier}>  $entries
     * @return list<string>
     */
    private function headerLines(array $entries, User $viewer): array
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
                if ($participant->role !== EmailParticipantRole::BCC || $email->user_id === $viewer->getKey()) {
                    $participantAddresses[] = $participant->email_address;
                }
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
}
