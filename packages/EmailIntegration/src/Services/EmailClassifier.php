<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\EmailCategory;

/**
 * Derives a coarse {@see EmailCategory} from cheap, high-signal heuristics:
 * the provider's own native category, calendar (.ics) parts, role-addressed
 * senders (billing@, support@, no-reply@) and subject keywords.
 *
 * Deliberately rule-based, not LLM-backed: the buckets are coarse enough that
 * deterministic rules give good precision with zero per-email cost, no latency,
 * and no message body leaving the system. Ambiguous mail falls through to
 * {@see EmailCategory::Other} rather than being guessed at.
 */
final readonly class EmailClassifier
{
    /** @var list<string> */
    private const array SCHEDULING_SUBJECTS = ['meeting', 'calendar', 'reschedul', 'appointment', 'availability', 'webinar', 'invitation:'];

    /** @var list<string> */
    private const array INVOICE_SENDERS = ['billing', 'invoice', 'invoices', 'receipts', 'payments'];

    /** @var list<string> */
    private const array INVOICE_SUBJECTS = ['invoice', 'receipt', 'payment received', 'order #', 'order confirmation', 'statement', 'your bill', 'past due'];

    /** @var list<string> */
    private const array SUPPORT_SENDERS = ['support', 'help', 'helpdesk'];

    /** @var list<string> */
    private const array SUPPORT_SUBJECTS = ['ticket #', 'case #', 'support request', 'we received your request'];

    /** @var list<string> */
    private const array SALES_SUBJECTS = ['quote', 'proposal', 'pricing', 'sales inquiry', 'request for proposal'];

    /** @var list<string> */
    private const array MARKETING_SENDERS = ['no-reply', 'noreply', 'newsletter', 'marketing', 'mailer', 'notification', 'notifications'];

    public function classify(FetchedEmailData $data, bool $isInternal): EmailCategory
    {
        // The provider already classified it natively (e.g. Gmail CATEGORY_*).
        if ($data->providerCategory instanceof EmailCategory) {
            return $data->providerCategory;
        }

        $subject = mb_strtolower(trim($data->subject ?? ''));
        $sender = $this->senderAddress($data);
        $localPart = str_contains($sender, '@') ? (string) strstr($sender, '@', true) : $sender;

        return match (true) {
            $this->hasCalendarPart($data) || $this->containsAny($subject, self::SCHEDULING_SUBJECTS) => EmailCategory::Scheduling,
            $this->containsAny($localPart, self::INVOICE_SENDERS) || $this->containsAny($subject, self::INVOICE_SUBJECTS) => EmailCategory::Invoice,
            $this->containsAny($localPart, self::SUPPORT_SENDERS) || $this->containsAny($subject, self::SUPPORT_SUBJECTS) => EmailCategory::Support,
            $this->containsAny($subject, self::SALES_SUBJECTS) => EmailCategory::Sales,
            $this->containsAny($localPart, self::MARKETING_SENDERS) => EmailCategory::Marketing,
            $isInternal => EmailCategory::Personal,
            default => EmailCategory::Other,
        };
    }

    private function senderAddress(FetchedEmailData $data): string
    {
        foreach ($data->participants as $participant) {
            if ($participant['role'] === 'from') {
                return mb_strtolower($participant['email_address']);
            }
        }

        return '';
    }

    private function hasCalendarPart(FetchedEmailData $data): bool
    {
        foreach ($data->attachments as $attachment) {
            if (($attachment['mime_type'] ?? null) === 'text/calendar') {
                return true;
            }

            $filename = $attachment['filename'] ?? null;
            if ($filename !== null && str_ends_with(mb_strtolower($filename), '.ics')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        if ($haystack === '') {
            return false;
        }

        return array_any($needles, fn (string $needle): bool => str_contains($haystack, $needle));
    }
}
