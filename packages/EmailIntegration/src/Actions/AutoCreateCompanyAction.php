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
        $company = Company::query()
            ->updateOrCreate([
                'name' => $this->domainToCompanyName($domain),
                'team_id' => $teamId,
                'creation_source' => CreationSource::SYSTEM,
            ]);

        $domainsField = $this->customFieldByCode('domains', $teamId);

        if ($domainsField instanceof BaseCustomField) {
            $company->saveCustomFieldValue($domainsField, $domain, $team);
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
     * Convert "acme.com" → "Acme" as a sensible default company name.
     */
    private function domainToCompanyName(string $domain): string
    {
        $parts = explode('.', $domain);

        return ucfirst($parts[0]);
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
