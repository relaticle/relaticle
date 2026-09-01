<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\Team;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
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

        $person->loadMissing('team', 'customFieldValues.customField');

        $team = $person->team;

        if (! $team instanceof Team) {
            return;
        }

        $teamId = $team->getKey();
        $workDomain = $this->firstWorkDomain($person, $teamId);

        if ($workDomain === null) {
            return;
        }

        $company = $this->domainMatcher->firstMatching($workDomain, $teamId);

        if (! $company instanceof Company && $team->auto_create_companies) {
            $company = $this->autoCreateCompany->execute($workDomain, $teamId, $team);
        }

        if (! $company instanceof Company) {
            return;
        }

        $person->update(['company_id' => $company->getKey()]);
    }

    private function firstWorkDomain(People $person, string $teamId): ?string
    {
        $emailField = $this->emailsCustomField($teamId);

        if (! $emailField instanceof BaseCustomField) {
            return null;
        }

        $value = $person->getCustomFieldValue($emailField);
        $emails = is_array($value) ? $value : ($value !== null && $value !== '' ? [(string) $value] : []);

        foreach ($emails as $email) {
            if (! is_string($email) || blank($email)) {
                continue;
            }

            $domain = $this->extractDomain($email);

            if ($domain === null) {
                continue;
            }

            if ($this->publicDomainList->contains($teamId, $domain)) {
                continue;
            }

            return $domain;
        }

        return null;
    }

    private function emailsCustomField(string $teamId): ?BaseCustomField
    {
        return CustomField::query()
            ->where('code', PeopleField::EMAILS->value)
            ->where('entity_type', 'people')
            ->where('tenant_id', $teamId)
            ->first();
    }

    private function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);

        return count($parts) === 2 ? strtolower($parts[1]) : null;
    }
}
