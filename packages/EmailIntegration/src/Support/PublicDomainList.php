<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

final class PublicDomainList
{
    /** @return Collection<int, lowercase-string> */
    public function forTeam(string $teamId): Collection
    {
        $configDomains = collect((array) config('email-integration.public_domains', []))
            ->map(fn (mixed $d): string => strtolower((string) $d));

        $teamDomains = PublicEmailDomain::query()->where('team_id', $teamId)
            ->pluck('domain')
            ->map(fn (mixed $d): string => strtolower((string) $d));

        return $configDomains->merge($teamDomains)->unique()->values();
    }

    public function contains(string $teamId, string $domain): bool
    {
        return $this->forTeam($teamId)->contains(strtolower($domain));
    }
}
