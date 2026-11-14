<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Enums\AutomatedReason;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailFolder;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Models\AutomatedSenderOverride;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Support\AutomatedSenderMatcher;
use Relaticle\EmailIntegration\Support\RelayedSender;

final readonly class AutomatedMailDetector
{
    private const array BULK_PRECEDENCE = ['bulk', 'junk', 'list'];

    public function __construct(private AutomatedSenderMatcher $roleSenders, private RelayedSender $relayed) {}

    public function detect(FetchedEmailData $data, string $workspaceId): ?AutomatedReason
    {
        if ($this->isOwnMail($data->direction, $data->folder)) {
            return null;
        }

        $sender = $this->sender($data->participants);
        $reason = $this->byHeaders($data->headers) ?? $this->bySender($data->participants, $sender, $data->isBulkMail);

        return $this->unlessPerson($reason, $workspaceId, $sender);
    }

    public function detectStored(Email $email, bool $labelledMarketing): ?AutomatedReason
    {
        $participants = $email->participants
            ->map(fn (EmailParticipant $participant): array => [
                'email_address' => $participant->email_address,
                'name' => $participant->name,
                'role' => $participant->role->value,
            ])
            ->all();

        if ($this->isOwnMail($email->direction, $email->folder) || $this->relayed->matches($participants, false)) {
            return null;
        }

        $sender = $this->sender($participants);
        $reason = $this->bySender($participants, $sender, false) ?? ($labelledMarketing ? AutomatedReason::RoleSender : null);

        return $this->unlessPerson($reason, $email->workspace_id, $sender);
    }

    private function isOwnMail(EmailDirection $direction, ?EmailFolder $folder): bool
    {
        return $direction === EmailDirection::OUTBOUND || $folder === EmailFolder::Drafts;
    }

    /** @param  array<int, array{email_address: string, name: string|null, role: string}>  $participants */
    private function bySender(array $participants, ?string $sender, bool $isBulkMail): ?AutomatedReason
    {
        return match (true) {
            $this->relayed->matches($participants, false) => null,
            $sender !== null && $this->roleSenders->matches($sender) => AutomatedReason::RoleSender,
            $isBulkMail => AutomatedReason::ProviderCategory,
            default => null,
        };
    }

    /** @param  array<string, string>  $headers */
    private function byHeaders(array $headers): ?AutomatedReason
    {
        $headers = array_map(fn (string $value): string => Str::lower(trim($value)), $headers);
        // RFC 3834 section 5: "no" may carry parameters or a comment, as in "no (manual)".
        $autoSubmitted = $headers['auto-submitted'] ?? '';
        $autoSubmitted = trim(substr($autoSubmitted, 0, strcspn($autoSubmitted, ';(')));

        return match (true) {
            filled($headers['list-unsubscribe'] ?? null) || filled($headers['list-id'] ?? null) => AutomatedReason::List,
            $autoSubmitted !== '' && $autoSubmitted !== 'no' => AutomatedReason::AutoSubmitted,
            in_array($headers['precedence'] ?? '', self::BULK_PRECEDENCE, true) => AutomatedReason::Bulk,
            default => null,
        };
    }

    private function unlessPerson(?AutomatedReason $reason, string $workspaceId, ?string $sender): ?AutomatedReason
    {
        if (! $reason instanceof AutomatedReason || $sender === null) {
            return $reason;
        }

        $isPerson = AutomatedSenderOverride::query()
            ->where('workspace_id', $workspaceId)
            ->where('email_address', $sender)
            ->exists();

        return $isPerson ? null : $reason;
    }

    /** @param  array<int, array{email_address: string, name: string|null, role: string}>  $participants */
    private function sender(array $participants): ?string
    {
        $from = collect($participants)->firstWhere('role', EmailParticipantRole::FROM->value);

        return $from === null ? null : Str::lower($from['email_address']);
    }
}
