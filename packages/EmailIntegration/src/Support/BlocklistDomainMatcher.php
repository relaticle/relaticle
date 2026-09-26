<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Str;

final class BlocklistDomainMatcher
{
    public function matchesEmailAddress(string $emailAddress, string $blockedDomain, bool $includeSubdomains): bool
    {
        $domain = $this->domainFromEmail($emailAddress);

        if ($domain === null) {
            return false;
        }

        return $this->matchesHost($domain, $blockedDomain, $includeSubdomains);
    }

    public function matchesHost(string $host, string $blockedDomain, bool $includeSubdomains): bool
    {
        $host = strtolower($host);
        $blockedDomain = strtolower($blockedDomain);

        if ($host === $blockedDomain) {
            return true;
        }

        if (! $includeSubdomains) {
            return false;
        }

        return str_ends_with($host, '.'.$blockedDomain);
    }

    /**
     * @param  literal-string  $blockedValueColumn
     * @param  literal-string  $participantAddressColumn
     * @param  literal-string  $includeSubdomainsColumn
     */
    public function constrainWhereExistsDomainMatch(
        BaseBuilder $query,
        string $blockedValueColumn,
        string $participantAddressColumn,
        string $includeSubdomainsColumn = 'include_subdomains',
    ): void {
        $valueExpression = "lower({$blockedValueColumn})";
        $addressExpression = "lower({$participantAddressColumn})";

        $query->where(function (BaseBuilder $match) use (
            $valueExpression,
            $addressExpression,
            $includeSubdomainsColumn,
        ): void {
            $match->where(function (BaseBuilder $exactOnly) use (
                $valueExpression,
                $addressExpression,
                $includeSubdomainsColumn,
            ): void {
                $exactOnly
                    ->where($includeSubdomainsColumn, false)
                    ->whereRaw("{$addressExpression} like '%@' || {$valueExpression}");
            })->orWhere(function (BaseBuilder $withSubdomains) use (
                $valueExpression,
                $addressExpression,
                $includeSubdomainsColumn,
            ): void {
                $withSubdomains
                    ->where($includeSubdomainsColumn, true)
                    ->where(function (BaseBuilder $patterns) use ($valueExpression, $addressExpression): void {
                        $patterns
                            ->whereRaw("{$addressExpression} like '%@' || {$valueExpression}")
                            ->orWhereRaw("{$addressExpression} like '%@%.' || {$valueExpression}");
                    });
            });
        });
    }

    private function domainFromEmail(string $email): ?string
    {
        $email = strtolower(trim($email));

        if (! str_contains($email, '@')) {
            return null;
        }

        $domain = Str::afterLast($email, '@');

        return $domain !== '' ? $domain : null;
    }
}
