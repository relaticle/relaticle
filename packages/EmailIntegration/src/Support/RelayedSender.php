<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;

final readonly class RelayedSender
{
    public function __construct(private PublicSuffixList $publicSuffixList) {}

    /**
     * @param  array<int, array{email_address: string, name: string|null, role: string}>  $participants
     */
    public function matches(array $participants, bool $hasListUnsubscribe): bool
    {
        // A form notification names the visitor in Reply-To and has no List-Unsubscribe.
        // A newsletter that replies to another domain still carries one.
        if ($hasListUnsubscribe) {
            return false;
        }

        $participants = collect($participants);
        $senderDomain = $this->registrableDomainOf($participants->firstWhere('role', EmailParticipantRole::FROM->value)['email_address'] ?? null);

        if ($senderDomain === null) {
            return false;
        }

        return $participants
            ->where('role', EmailParticipantRole::REPLY_TO->value)
            ->contains(function (array $participant) use ($senderDomain): bool {
                $replyDomain = $this->registrableDomainOf($participant['email_address']);

                return $replyDomain !== null && $replyDomain !== $senderDomain;
            });
    }

    private function registrableDomainOf(?string $address): ?string
    {
        if ($address === null || ! str_contains($address, '@')) {
            return null;
        }

        return $this->publicSuffixList->registrableDomain(Str::afterLast($address, '@'));
    }
}
