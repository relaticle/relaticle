<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\CreationSource;
use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Support\CurrentSource;
use App\Support\Database\AdvisoryLock;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\EmailIntegration\Support\CompanyDomainMatcher;
use Relaticle\EmailIntegration\Support\PublicSuffixList;

final readonly class AutoCreateCompanyAction
{
    public function __construct(
        private CompanyDomainMatcher $domainMatcher,
        private AdvisoryLock $advisoryLock,
        private PublicSuffixList $publicSuffixList,
    ) {}

    /**
     * Resolve a Company for the domain, creating one only when no existing company
     * already owns it. Serialised per (workspace, domain) with a transaction-level
     * advisory lock so two StoreEmailJob/calendar workers processing the first
     * email from a brand-new domain in parallel can't both miss the match and
     * create duplicate companies: the first holder creates, the rest re-check
     * inside the lock and reuse it. The lock key is the full host (www.
     * stripped). Distinct hosts such as accounts.printtest.com and
     * ideas.printtest.com do not share a lock.
     */
    public function execute(string $domain, string $workspaceId): Company
    {
        $host = $this->domainMatcher->host($domain);

        $source = CurrentSource::bound() ?? CreationSource::MAILBOX;

        return CurrentSource::during($source, fn (): Company => $this->advisoryLock->transactional("auto-create-company:{$workspaceId}:{$host}", function () use ($host, $workspaceId): Company {
            // Only create when the domain is not already in another company. The
            // caller's unlocked match can be stale by the time we get the lock, so
            // re-check here under mutual exclusion before creating.
            $existing = $this->domainMatcher->firstMatching($host, $workspaceId);

            if ($existing instanceof Company) {
                return $existing;
            }

            return $this->createCompany($host, $workspaceId);
        }));
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
    private function createCompany(string $domain, string $workspaceId): Company
    {
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($workspaceId);

        try {
            return Company::query()->create([
                'name' => $this->domainToCompanyName($domain),
                'workspace_id' => $workspaceId,
                'custom_fields' => [
                    CompanyField::DOMAINS->value => [$domain],
                    CompanyField::ICP->value => false,
                ],
            ]);
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }
    }

    /**
     * Convert a domain to a sensible default company name using the registrable
     * label resolved against the Public Suffix List, so mail subdomains and
     * multi-part TLDs never leak in: "acme.com" → "Acme",
     * "email.anthropic.com" → "Anthropic", "mail.acme.co.uk" → "Acme".
     */
    private function domainToCompanyName(string $domain): string
    {
        $label = $this->publicSuffixList->registrableLabel($domain) ?? explode('.', $domain)[0];

        return ucfirst($label);
    }
}
