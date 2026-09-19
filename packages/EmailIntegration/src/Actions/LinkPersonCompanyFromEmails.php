<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\Workspace;
use App\Support\EmailAddress;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\EmailIntegration\Support\CompanyDomainMatcher;
use Relaticle\EmailIntegration\Support\PublicDomainList;

final readonly class LinkPersonCompanyFromEmails
{
    public function __construct(
        private AutoCreateCompanyAction $autoCreateCompany,
        private CompanyDomainMatcher $domainMatcher,
        private PublicDomainList $publicDomainList,
    ) {}

    public function execute(People $person): void
    {
        if ($person->company_id !== null) {
            return;
        }

        $person->loadMissing('workspace');

        $workspace = $person->workspace;

        if (! $workspace instanceof Workspace) {
            return;
        }

        $workspaceId = $workspace->getKey();
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($workspaceId);

        try {
            $person->unsetRelation('customFieldValues');
            $person->loadMissing('customFieldValues.customField');

            $workDomain = $this->firstWorkDomain(
                $person,
                $workspaceId,
                $this->publicDomainList->forWorkspace($workspaceId),
            );

            if ($workDomain === null) {
                return;
            }

            $company = $this->domainMatcher->firstMatching($workDomain, $workspaceId);

            if (! $company instanceof Company && $workspace->auto_create_companies) {
                $company = $this->autoCreateCompany->execute($workDomain, $workspaceId, $workspace);
            }

            if (! $company instanceof Company) {
                return;
            }

            $person->update(['company_id' => $company->getKey()]);
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }
    }

    /**
     * @param  Collection<int, lowercase-string>  $skippedDomains
     */
    private function firstWorkDomain(People $person, string $workspaceId, Collection $skippedDomains): ?string
    {
        $emailField = $this->emailsCustomField($workspaceId);

        if (! $emailField instanceof BaseCustomField) {
            return null;
        }

        $value = $person->getCustomFieldValue($emailField);
        $emails = is_array($value) ? $value : ($value !== null && $value !== '' ? [(string) $value] : []);

        foreach ($emails as $email) {
            if (! is_string($email) || blank($email)) {
                continue;
            }

            $host = $this->hostFromEmail($email);

            if ($host === null || $skippedDomains->contains($host)) {
                continue;
            }

            return $host;
        }

        return null;
    }

    private function emailsCustomField(string $workspaceId): ?BaseCustomField
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('code', PeopleField::EMAILS->value)
            ->where('entity_type', 'people')
            ->where('tenant_id', $workspaceId)
            ->first();
    }

    private function hostFromEmail(string $email): ?string
    {
        $canonical = EmailAddress::canonicalize($email);
        $parts = explode('@', $canonical);

        if (count($parts) !== 2 || $parts[1] === '') {
            return null;
        }

        return $this->domainMatcher->host($parts[1]);
    }
}
