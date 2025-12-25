<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use Relaticle\ImportWizard\Data\CompanyMatchResult;

/**
 * Smart company matching service for import previews.
 *
 * Matching priority:
 * 1. Domain match: email domain → company domains custom field
 * 2. Name match: exact company name match
 *
 * Returns match type for transparent preview display.
 *
 * Performance: Pre-loads all companies for a team into memory to avoid N+1 queries.
 */
final class CompanyMatcher
{
    /**
     * Cached companies indexed by name and domain for fast lookup.
     *
     * @var array{byName: array<string, array<int, Company>>, byDomain: array<string, array<int, Company>>}|null
     */
    private ?array $companyCache = null;

    private ?string $cachedTeamId = null;

    /**
     * Match a company by domain (from emails) or name.
     *
     * Priority:
     * 1. Domain match (from email) - highest confidence
     * 2. Name match - fallback
     *
     * When company_name is empty but emails are provided, will try domain matching.
     * This enables auto-linking people to companies based on email domain (like Attio).
     *
     * @param  array<string>  $emails  Person's email addresses
     */
    public function match(string $companyName, array $emails, string $teamId): CompanyMatchResult
    {
        $companyName = trim($companyName);

        // Load companies into cache on first call
        $this->ensureCompaniesLoaded($teamId);

        // Step 1: Try domain matching (highest confidence)
        $domains = $this->extractEmailDomains($emails);
        if ($domains !== []) {
            $domainMatches = $this->findInCacheByDomain($domains);

            if (count($domainMatches) === 1) {
                $company = reset($domainMatches);

                return new CompanyMatchResult(
                    companyName: $company->name,
                    matchType: 'domain',
                    matchCount: 1,
                    companyId: (string) $company->id,
                );
            }

            // Multiple domain matches - ambiguous
            if (count($domainMatches) > 1) {
                return new CompanyMatchResult(
                    companyName: $companyName ?: 'Unknown',
                    matchType: 'ambiguous',
                    matchCount: count($domainMatches),
                );
            }
        }

        // No company name provided and no domain match - nothing to match
        if ($companyName === '') {
            return new CompanyMatchResult(
                companyName: '',
                matchType: 'new',
                matchCount: 0,
            );
        }

        // Step 2: Try exact name matching
        $nameMatches = $this->findInCacheByName($companyName);

        if (count($nameMatches) === 1) {
            $company = reset($nameMatches);

            return new CompanyMatchResult(
                companyName: $company->name,
                matchType: 'name',
                matchCount: 1,
                companyId: (string) $company->id,
            );
        }

        if (count($nameMatches) > 1) {
            return new CompanyMatchResult(
                companyName: $companyName,
                matchType: 'ambiguous',
                matchCount: count($nameMatches),
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
     * Load all companies for team into memory cache.
     */
    private function ensureCompaniesLoaded(string $teamId): void
    {
        // Cache already loaded for this team
        if ($this->companyCache !== null && $this->cachedTeamId === $teamId) {
            return;
        }

        $this->cachedTeamId = $teamId;
        $this->companyCache = ['byName' => [], 'byDomain' => []];

        // Load all companies with custom field values
        // Use withoutGlobalScopes on the relationship to ensure we get all custom field data
        $companies = Company::query()
            ->where('team_id', $teamId)
            ->with([
                'customFieldValues' => fn (\Illuminate\Database\Eloquent\Relations\Relation $query) => $query->withoutGlobalScopes()->with([
                    'customField' => fn (\Illuminate\Database\Eloquent\Relations\Relation $q) => $q->withoutGlobalScopes(),
                ]),
            ])
            ->get();

        // Index by name
        foreach ($companies as $company) {
            $name = $company->name;
            if (! isset($this->companyCache['byName'][$name])) {
                $this->companyCache['byName'][$name] = [];
            }
            $this->companyCache['byName'][$name][] = $company;
        }

        // Index by domains custom field (stored as json_value array)
        foreach ($companies as $company) {
            $domainValue = $company->customFieldValues
                // @phpstan-ignore notIdentical.alwaysTrue (defensive check for safety)
                ->filter(fn (\App\Models\CustomFieldValue $cfv): bool => $cfv->customField !== null)
                ->first(fn (\App\Models\CustomFieldValue $cfv): bool => $cfv->customField->code === CompanyField::DOMAINS->value);

            if ($domainValue !== null && is_array($domainValue->json_value)) {
                foreach ($domainValue->json_value as $domain) {
                    $domain = strtolower(trim((string) $domain));
                    if ($domain !== '' && ! isset($this->companyCache['byDomain'][$domain])) {
                        $this->companyCache['byDomain'][$domain] = [];
                    }
                    if ($domain !== '') {
                        $this->companyCache['byDomain'][$domain][] = $company;
                    }
                }
            }
        }
    }

    /**
     * Find companies in cache by domain.
     *
     * @param  array<string>  $domains
     * @return array<int, Company>
     */
    private function findInCacheByDomain(array $domains): array
    {
        $matches = [];

        foreach ($domains as $domain) {
            if (isset($this->companyCache['byDomain'][$domain])) {
                foreach ($this->companyCache['byDomain'][$domain] as $company) {
                    $matches[$company->id] = $company;
                }
            }
        }

        return array_values($matches);
    }

    /**
     * Find companies in cache by name.
     *
     * @return array<int, Company>
     */
    private function findInCacheByName(string $name): array
    {
        return $this->companyCache['byName'][$name] ?? [];
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
}
