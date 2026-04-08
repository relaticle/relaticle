<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Team;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;

final readonly class AutoCreateCompanyAction
{
    /**
     * Create a new Company record seeded with a name derived from the domain
     * and the domain stored in the domains custom field.
     */
    public function execute(string $domain, string $teamId, Team $team): Company
    {
        $company = Company::query()->create([
            'name' => $this->domainToCompanyName($domain),
            'team_id' => $teamId,
            'creation_source' => CreationSource::SYSTEM,
        ]);

        $domainsField = $this->customFieldByCode($teamId);

        if ($domainsField instanceof BaseCustomField) {
            $company->saveCustomFieldValue($domainsField, "https://{$domain}", $team);
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

    private function customFieldByCode(string $teamId): ?BaseCustomField
    {
        return CustomField::query()
            ->where('code', 'domains')
            ->where('entity_type', 'company')
            ->where('tenant_id', $teamId)
            ->first();
    }
}
