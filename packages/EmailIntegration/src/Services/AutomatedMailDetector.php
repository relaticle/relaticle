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
        $sender = $this->sender($data->participants);

        if ($this->isOwnMail($data->direction, $data->folder) || $this->isPerson($workspaceId, $sender)) {
            return null;
        }

        $headers = array_map(fn (string $value): string => Str::lower(trim($value)), $data->headers);

        return match (true) {
            isset($headers['list-unsubscribe']) || isset($headers['list-id']) => AutomatedReason::List,
            isset($headers['auto-submitted']) && $headers['auto-submitted'] !== 'no' => AutomatedReason::AutoSubmitted,
            in_array($headers['precedence'] ?? '', self::BULK_PRECEDENCE, true) => AutomatedReason::Bulk,
            default => $this->bySender($data->participants, $sender, $data->isBulkMail),
        };
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
        $sender = $this->sender($participants);

        if ($this->isOwnMail($email->direction, $email->folder) || $this->isPerson($email->workspace_id, $sender) || $this->relayed->matches($participants, false)) {
            return null;
        }

        return $this->bySender($participants, $sender, false) ?? ($labelledMarketing ? AutomatedReason::RoleSender : null);
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

    private function isPerson(string $workspaceId, ?string $sender): bool
    {
        return $sender !== null && AutomatedSenderOverride::query()
            ->where('workspace_id', $workspaceId)
            ->where('email_address', $sender)
            ->exists();
    }

    /** @param  array<int, array{email_address: string, name: string|null, role: string}>  $participants */
    private function sender(array $participants): ?string
    {
        $from = collect($participants)->firstWhere('role', EmailParticipantRole::FROM->value);

        return $from === null ? null : Str::lower($from['email_address']);
    }
}
