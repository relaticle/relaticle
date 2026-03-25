<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use App\Models\Team;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

final readonly class EmailAutoCreateService
{
    /**
     * Determine whether a new Person should be created for the given email address,
     * based on the account's contact_creation_mode setting.
     *
     * - All:          always create when the address is unknown
     * - Bidirectional: only create when the connected account has exchanged email in
     *                  BOTH directions with this address (requires at least one inbound
     *                  AND one outbound message already stored)
     * - None:         never create (default)
     */
    public function shouldCreatePerson(
        ConnectedAccount $account,
        string $emailAddress,
    ): bool {
        return match ($account->contact_creation_mode) {
            ContactCreationMode::All => true,
            ContactCreationMode::Bidirectional => $this->hasBidirectionalHistory($account, $emailAddress),
            ContactCreationMode::None => false,
        };
    }

    /**
     * Create a new Person record seeded with name + email custom field value.
     * The person is created with CreationSource::SYSTEM so it is distinguishable
     * from manually created records.
     */
    public function createPerson(string $name, string $emailAddress, string $teamId, Team $team): People
    {
        $person = People::create([
            'name' => $name ?: $emailAddress,
            'team_id' => $teamId,
            'creation_source' => CreationSource::SYSTEM,
        ]);

        $emailField = $this->customFieldByCode('emails', 'people', $teamId);

        if ($emailField) {
            $person->saveCustomFieldValue($emailField, [$emailAddress], $team);
        }

        return $person;
    }

    /**
     * Create a new Company record seeded with name derived from domain and the
     * domain stored in the domains custom field.
     */
    public function createCompany(string $domain, string $teamId, Team $team): Company
    {
        $company = Company::create([
            'name' => $this->domainToCompanyName($domain),
            'team_id' => $teamId,
            'creation_source' => CreationSource::SYSTEM,
        ]);

        $domainsField = $this->customFieldByCode('domains', 'company', $teamId);

        if ($domainsField) {
            $company->saveCustomFieldValue($domainsField, "https://{$domain}", $team);
        }

        return $company;
    }

    /**
     * Returns true if the account already has at least one stored email in each
     * direction involving the given address.
     */
    private function hasBidirectionalHistory(ConnectedAccount $account, string $emailAddress): bool
    {
        $directions = Email::where('connected_account_id', $account->getKey())
            ->whereHas('participants', fn ($q) => $q->where('email_address', $emailAddress))
            ->distinct()
            ->pluck('direction');

        $values = $directions->map(fn ($d) => $d instanceof EmailDirection ? $d->value : $d);

        return $values->contains(EmailDirection::INBOUND->value)
            && $values->contains(EmailDirection::OUTBOUND->value);
    }

    /**
     * Find a custom field by code + entityType scoped to this team.
     * Returns null if no matching field is configured (graceful degradation).
     */
    private function customFieldByCode(string $code, string $entityType, string $teamId): ?CustomField
    {
        return CustomField::where('code', $code)
            ->where('entity_type', $entityType)
            ->where('tenant_id', $teamId)
            ->first();
    }

    /**
     * Convert "acme.com" → "Acme" as a sensible default company name.
     */
    private function domainToCompanyName(string $domain): string
    {
        $parts = explode('.', $domain);

        return ucfirst($parts[0]);
    }
}
