<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Models\CustomFieldValue;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Data\CompanyMatchResult;

final class CompanyMatcher
{
    /** @var array{byId: array<string, Company>, byDomain: array<string, array<int, Company>>}|null */
    private ?array $companyCache = null;

    private ?string $cachedTeamId = null;

    /** @param array<string> $emails */
    public function match(string $companyId, string $companyName, array $emails, string $teamId): CompanyMatchResult
    {
        $companyId = trim($companyId);
        $companyName = trim($companyName);

        // Load companies into cache on first call
        $this->ensureCompaniesLoaded($teamId);

        // Priority 1: ID matching (highest confidence)
        if ($companyId !== '' && Str::isUlid($companyId)) {
            $company = $this->findInCacheById($companyId);
            if ($company instanceof Company) {
                return new CompanyMatchResult(
                    companyName: $company->name,
                    matchType: 'id',
                    matchCount: 1,
                    companyId: (string) $company->id,
                );
            }
        }

        // Priority 2: Domain matching (second highest confidence)
        $domains = $this->extractEmailDomains($emails);
        if ($domains !== []) {
            $domainMatches = $this->findInCacheByDomain($domains);

            if (count($domainMatches) >= 1) {
                // Domain field is unique per company, so take first match
                $company = reset($domainMatches);

                return new CompanyMatchResult(
                    companyName: $company->name,
                    matchType: 'domain',
                    matchCount: count($domainMatches),
                    companyId: (string) $company->id,
                );
            }
        }

        // Priority 3: No company data → no association
        if ($companyName === '') {
            return new CompanyMatchResult(
                companyName: '',
                matchType: 'none',
                matchCount: 0,
            );
        }

        // Priority 4: company_name provided → will create new company
        return new CompanyMatchResult(
            companyName: $companyName,
            matchType: 'new',
            matchCount: 0,
        );
    }

    private function ensureCompaniesLoaded(string $teamId): void
    {
        // Cache already loaded for this team
        if ($this->companyCache !== null && $this->cachedTeamId === $teamId) {
            return;
        }

        $this->cachedTeamId = $teamId;
        $this->companyCache = ['byId' => [], 'byDomain' => []];

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

        // Index by ID
        foreach ($companies as $company) {
            /** @var string $id */
            $id = (string) $company->id;
            $this->companyCache['byId'][$id] = $company;
        }

        // Index by domains custom field (stored as json_value collection)
        foreach ($companies as $company) {
            /** @var CustomFieldValue|null $domainValue */
            $domainValue = $company->customFieldValues
                ->first(fn (CustomFieldValue $cfv): bool => $cfv->customField->code === CompanyField::DOMAINS->value);

            if ($domainValue === null) {
                continue;
            }

            // json_value is cast to Collection in the model
            $domains = $domainValue->json_value ?? collect();

            foreach ($domains as $domain) {
                $domain = strtolower(trim((string) $domain));
                if ($domain === '') {
                    continue;
                }
                $this->companyCache['byDomain'][$domain] ??= [];
                $this->companyCache['byDomain'][$domain][] = $company;
            }
        }
    }

    private function findInCacheById(string $id): ?Company
    {
        return $this->companyCache['byId'][$id] ?? null;
    }

    /**
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
