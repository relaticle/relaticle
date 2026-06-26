<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Team;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\EmailIntegration\Support\CompanyDomainMatcher;
use Relaticle\EmailIntegration\Support\UsesTransactionalLock;

final readonly class AutoCreateCompanyAction
{
    use UsesTransactionalLock;

    public function __construct(
        private CompanyDomainMatcher $domainMatcher,
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
        return $this->transactional("auto-create-company:{$teamId}:{$domain}", function () use ($domain, $teamId, $team): Company {
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
     */
    private function createCompany(string $domain, string $teamId, Team $team): Company
    {
        $company = Company::query()
            ->updateOrCreate([
                'name' => $this->domainToCompanyName($domain),
                'team_id' => $teamId,
                'creation_source' => CreationSource::SYSTEM,
            ]);

        $domainsField = $this->customFieldByCode('domains', $teamId);

        if ($domainsField instanceof BaseCustomField) {
            $company->saveCustomFieldValue($domainsField, $this->toWwwDomain($domain), $team);
        }

        // Seed the ICP toggle to false on creation so it renders as "No"
        // rather than an empty/null cell. Existing companies keep their value.
        if ($company->wasRecentlyCreated) {
            $icpField = $this->customFieldByCode('icp', $teamId);

            if ($icpField instanceof BaseCustomField) {
                $company->saveCustomFieldValue($icpField, false, $team);
            }
        }

        return $company;
    }

    /**
     * Common multi-label public suffixes. The leftmost label of these is part of
     * the TLD, not the org — so the org label sits one position further left.
     *
     * ponytail: hand-list of the realistic B2B subset, not the full Public Suffix
     * List. An unlisted multi-part suffix falls back to the single-label rule
     * (e.g. a rare ".pvt.k12.ma.us"). Swap in jeremykendall/php-domain-parser if
     * exhaustive coverage is ever needed.
     *
     * @var list<string>
     */
    private const array MULTI_LABEL_SUFFIXES = [
        'co.uk', 'org.uk', 'ac.uk', 'gov.uk', 'me.uk', 'ltd.uk', 'plc.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au',
        'co.nz', 'co.za', 'co.jp', 'co.kr', 'co.in', 'co.id', 'co.il', 'co.th',
        'com.br', 'com.mx', 'com.sg', 'com.tr', 'com.hk', 'com.tw', 'com.cn',
    ];

    /**
     * Convert a domain to a sensible default company name using the registrable
     * label — the org part right before the public suffix — so mail subdomains
     * and multi-part TLDs don't leak in: "acme.com" → "Acme",
     * "email.anthropic.com" → "Anthropic", "mail.acme.co.uk" → "Acme".
     */
    private function domainToCompanyName(string $domain): string
    {
        $parts = explode('.', $domain);
        $suffixLabels = $this->suffixLabelCount($parts);
        $label = $parts[count($parts) - $suffixLabels - 1] ?? $parts[0];

        return ucfirst($label);
    }

    /**
     * Number of labels the public suffix spans: 2 for known multi-label suffixes
     * (co.uk), 1 otherwise (com, io, anthropic.com's "com").
     *
     * @param  list<string>  $parts
     */
    private function suffixLabelCount(array $parts): int
    {
        if (count($parts) >= 3 && in_array(implode('.', array_slice($parts, -2)), self::MULTI_LABEL_SUFFIXES, true)) {
            return 2;
        }

        return 1;
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
