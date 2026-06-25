<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Team;
use App\Support\Database\AdvisoryLock;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\EmailIntegration\Support\CompanyDomainMatcher;

final readonly class AutoCreateCompanyAction
{
    public function __construct(
        private CompanyDomainMatcher $domainMatcher,
        private AdvisoryLock $advisoryLock,
    ) {}

    /**
     * Resolve a Company for the domain, creating one only when no existing company
     * already owns it. Serialised per (team, domain) with a transaction-level
     * advisory lock so two StoreEmailJob/calendar workers processing the first
     * email from a brand-new domain in parallel can't both miss the match and
     * create duplicate companies: the first holder creates, the rest re-check
     * inside the lock and reuse it.
     */
    public function execute(string $domain, string $teamId, Team $team): Company
    {
        return $this->advisoryLock->transactional("auto-create-company:{$teamId}:{$domain}", function () use ($domain, $teamId, $team): Company {
            // Only create when the domain is not already in another company. The
            // caller's unlocked match can be stale by the time we get the lock, so
            // re-check here under mutual exclusion before creating.
            $existing = $this->domainMatcher->firstMatching($domain, $teamId);

            if ($existing instanceof Company) {
                return $existing;
            }

            return $this->createCompany($domain, $teamId, $team);
        });
    }

    /**
     * Create a new Company record seeded with a name derived from the domain
     * and the domain stored in the domains custom field.
     *
     * Only reached after firstMatching() confirmed (under the lock) that no
     * company owns this domain, so we always create a fresh record. The name is
     * just a default seed and must NOT be used as a dedup key: distinct domains
     * sharing a first label (acme.com vs acme.org) are distinct companies, and
     * keying on name would clobber an unrelated same-named company's domains.
     */
    private function createCompany(string $domain, string $teamId, Team $team): Company
    {
        $company = Company::query()->create([
            'name' => $this->domainToCompanyName($domain),
            'team_id' => $teamId,
            'creation_source' => CreationSource::SYSTEM,
        ]);

        $domainsField = $this->customFieldByCode('domains', $teamId);

        if ($domainsField instanceof BaseCustomField) {
            $company->saveCustomFieldValue($domainsField, $this->toWwwDomain($domain), $team);
        }

        // Seed the ICP toggle to false on creation so it renders as "No"
        // rather than an empty/null cell.
        $icpField = $this->customFieldByCode('icp', $teamId);

        if ($icpField instanceof BaseCustomField) {
            $company->saveCustomFieldValue($icpField, false, $team);
        }

        return $company;
    }

    /**
     * Convert "acme.com" → "Acme" as a sensible default company name.
     */
    private function domainToCompanyName(string $domain): string
    {
        $parts = explode('.', $domain);

        return ucfirst($parts[0]);
    }

    /**
     * Prefix the bare domain with "www." for display, without a scheme,
     * e.g. "acme.com" → "www.acme.com". Leaves an existing www. intact.
     */
    private function toWwwDomain(string $domain): string
    {
        return str_starts_with($domain, 'www.') ? $domain : "www.{$domain}";
    }

    private function customFieldByCode(string $code, string $teamId): ?BaseCustomField
    {
        return CustomField::query()
            ->where('code', $code)
            ->where('entity_type', 'company')
            ->where('tenant_id', $teamId)
            ->first();
    }
}
