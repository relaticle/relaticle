<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBlocklist;

final readonly class BlocklistService
{
    /**
     * Check if an email should be hidden from the owner's view
     * (i.e. any participant matches their blocklist).
     */
    public function isBlockedForOwner(Email $email): bool
    {
        $owner = $email->user;

        if (! $owner) {
            return false;
        }

        [$blockedEmails, $blockedDomains] = $this->loadBlocklist($owner, $email->team_id);

        foreach ($email->participants as $participant) {
            $address = strtolower($participant->email_address);
            $domain = explode('@', $address)[1] ?? '';

            if ($blockedEmails->contains($address) || $blockedDomains->contains($domain)) {
                return true;
            }
        }

        return false;
    }

    private function loadBlocklist(User $user, string $teamId): array
    {
        $rows = EmailBlocklist::where('user_id', $user->getKey())
            ->where('team_id', $teamId)
            ->get();

        $emails = $rows->where('type', 'email')->pluck('value')->map(fn ($v) => strtolower($v));
        $domains = $rows->where('type', 'domain')->pluck('value')->map(fn ($v) => strtolower($v));

        return [$emails, $domains];
    }
}
