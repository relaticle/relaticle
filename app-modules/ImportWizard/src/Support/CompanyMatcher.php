<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Models\CustomFieldValue;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Data\CompanyMatchResult;
use Relaticle\ImportWizard\Enums\MatchType;

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
                    matchType: MatchType::Id,
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
                    matchType: MatchType::Domain,
                    matchCount: count($domainMatches),
                    companyId: (string) $company->id,
                );
            }
        }

        // Priority 3: No company data → no association
        if ($companyName === '') {
            return new CompanyMatchResult(
                companyName: '',
                matchType: MatchType::None,
                matchCount: 0,
            );
        }

        // Priority 4: company_name provided → will create new company
        return new CompanyMatchResult(
            companyName: $companyName,
            matchType: MatchType::New,
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
        return collect($domains)
            ->flatMap(fn (string $domain): array => $this->companyCache['byDomain'][$domain] ?? [])
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * @param  array<string>  $emails
     * @return array<string>
     */
    private function extractEmailDomains(array $emails): array
    {
        return collect($emails)
            ->filter(fn (string $email): bool => str_contains($email, '@'))
            ->map(fn (string $email): string => str($email)->lower()->trim()->afterLast('@')->toString())
            ->unique()
            ->values()
            ->all();
    }
}
