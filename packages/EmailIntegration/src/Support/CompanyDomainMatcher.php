<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Models\Company;
use Illuminate\Contracts\Database\Query\Builder;

/**
 * Single source of truth for resolving an email/attendee domain to an existing
 * Company via the team's "domains" custom field. Shared by the email and meeting
 * link actions (fast path) and the system auto-create action (locked re-check),
 * so the matching semantics never drift between them.
 */
final class CompanyDomainMatcher
{
    /**
     * Find the first Company in the team whose "domains" custom field contains
     * the given domain as a complete host token. Returns null when no company
     * owns the domain.
     */
    public function firstMatching(string $domain, string $teamId): ?Company
    {
        return Company::query()->where('team_id', $teamId)
            ->whereHas('customFieldValues', fn (Builder $valueQuery) => $valueQuery
                ->whereHas('customField', fn (Builder $fieldQuery) => $fieldQuery->where('code', 'domains'))
                ->whereRaw('json_value::text ~* ?', [$this->matchPattern($domain)])
            )
            ->first();
    }

    /**
     * Build a POSIX regex matching the domain as a complete host token inside the
     * JSON-encoded domains array. Tolerates scheme/subdomain/path variants while
     * avoiding substring collisions (e.g. "acme.co" vs "acme.com.au") and
     * neutralising LIKE wildcards present in sender-controlled input.
     */
    public function matchPattern(string $domain): string
    {
        return '(^|["/.])'.preg_quote($domain).'(["/:]|$)';
    }
}
