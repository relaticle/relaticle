<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Models\PublicEmailDomain;

final readonly class PublicDomainList
{
    public function __construct(
        private CompanyDomainMatcher $domainMatcher,
    ) {}

    /** @return Collection<int, lowercase-string> */
    public function forWorkspace(string $workspaceId): Collection
    {
        $configDomains = collect((array) config('email-integration.public_domains', []))
            ->map(fn (mixed $d): string => strtolower($this->domainMatcher->host((string) $d)));

        $workspaceDomains = PublicEmailDomain::query()->where('workspace_id', $workspaceId)
            ->pluck('domain')
            ->map(fn (mixed $d): string => strtolower($this->domainMatcher->host((string) $d)));

        return $configDomains->merge($workspaceDomains)->unique()->values();
    }
}
