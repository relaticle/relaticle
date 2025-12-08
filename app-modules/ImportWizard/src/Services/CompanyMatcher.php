<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Relaticle\ImportWizard\Data\CompanyMatchResult;

/**
 * Smart company matching service for import previews.
 *
 * Matching priority:
 * 1. Domain match: email domain → company domain_name custom field
 * 2. Name match: exact company name match
 *
 * Returns match type for transparent preview display.
 */
final readonly class CompanyMatcher
{
    /**
     * Match a company by domain (from emails) or name.
     *
     * @param  array<string>  $emails  Person's email addresses
     */
    public function match(string $companyName, array $emails, int $teamId): CompanyMatchResult
    {
        $companyName = trim($companyName);

        if ($companyName === '') {
            return new CompanyMatchResult(
                companyName: '',
                matchType: 'new',
                matchCount: 0,
            );
        }

        // Step 1: Try domain matching (highest confidence)
        $domains = $this->extractEmailDomains($emails);
        if ($domains !== []) {
            $domainMatches = $this->findByDomain($domains, $teamId);

            if ($domainMatches->count() === 1) {
                /** @var Company $company */
                $company = $domainMatches->first();

                return new CompanyMatchResult(
                    companyName: $company->name,
                    matchType: 'domain',
                    matchCount: 1,
                    companyId: $company->id,
                );
            }

            // Multiple domain matches - ambiguous
            if ($domainMatches->count() > 1) {
                return new CompanyMatchResult(
                    companyName: $companyName,
                    matchType: 'ambiguous',
                    matchCount: $domainMatches->count(),
                );
            }
        }

        // Step 2: Try exact name matching
        $nameMatches = $this->findByName($companyName, $teamId);

        if ($nameMatches->count() === 1) {
            /** @var Company $company */
            $company = $nameMatches->first();

            return new CompanyMatchResult(
                companyName: $company->name,
                matchType: 'name',
                matchCount: 1,
                companyId: $company->id,
            );
        }

        if ($nameMatches->count() > 1) {
            return new CompanyMatchResult(
                companyName: $companyName,
                matchType: 'ambiguous',
                matchCount: $nameMatches->count(),
            );
        }

        // No matches - will create new company
        return new CompanyMatchResult(
            companyName: $companyName,
            matchType: 'new',
            matchCount: 0,
        );
    }

    /**
     * Extract unique domains from email addresses.
     *
     * @param  array<string>  $emails
     * @return array<string>
     */
    private function extractEmailDomains(array $emails): array
    {
        return collect($emails)
            ->map(function (mixed $email): ?string {
                $email = strtolower(trim($email));
                if (! str_contains($email, '@')) {
                    return null;
                }

                return substr($email, (int) strrpos($email, '@') + 1);
            })
            ->filter()
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Find companies by domain_name custom field.
     *
     * @param  array<string>  $domains
     * @return Collection<int, Company>
     */
    private function findByDomain(array $domains, int $teamId): Collection
    {
        return Company::query()
            ->where('team_id', $teamId)
            ->whereHas('customFieldValues', function (Builder $query) use ($domains, $teamId): void {
                // Use withoutGlobalScopes to bypass TenantScope since we explicitly filter by team_id
                $query->withoutGlobalScopes()
                    ->where('tenant_id', $teamId)
                    ->whereRelation('customField', fn (Builder $q) => $q
                        ->withoutGlobalScopes()
                        ->where('code', 'domain_name')
                        ->where('tenant_id', $teamId))
                    ->whereIn('string_value', $domains);
            })
            ->get();
    }

    /**
     * Find companies by exact name match.
     *
     * @return Collection<int, Company>
     */
    private function findByName(string $name, int $teamId): Collection
    {
        return Company::query()
            ->where('team_id', $teamId)
            ->where('name', $name)
            ->get();
    }
}
