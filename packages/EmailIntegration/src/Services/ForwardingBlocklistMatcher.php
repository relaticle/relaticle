<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Models\UserForwardingBlocklist;

final readonly class ForwardingBlocklistMatcher
{
    /**
     * @param  list<string>  $addresses
     */
    public function isBlocked(string $userId, string $teamId, array $addresses): bool
    {
        if ($addresses === []) {
            return false;
        }

        $rows = UserForwardingBlocklist::query()
            ->where('user_id', $userId)
            ->where('team_id', $teamId)
            ->get();

        if ($rows->isEmpty()) {
            return false;
        }

        return Collection::make($addresses)
            ->contains(fn (string $address): bool => $this->matchesAny($address, $rows));
    }

    /**
     * @param  Collection<int, UserForwardingBlocklist>  $rows
     */
    private function matchesAny(string $address, Collection $rows): bool
    {
        $normalized = strtolower(trim($address));

        foreach ($rows as $row) {
            if ($row->type === EmailBlocklistType::EMAIL && $row->value === $normalized) {
                return true;
            }

            if ($row->type === EmailBlocklistType::DOMAIN) {
                $domain = $this->domainFromEmail($normalized);

                if ($domain !== null && $domain === $row->value) {
                    return true;
                }
            }
        }

        return false;
    }

    private function domainFromEmail(string $email): ?string
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        $domain = strtolower(substr($email, $at + 1));

        return $domain !== '' ? $domain : null;
    }
}
