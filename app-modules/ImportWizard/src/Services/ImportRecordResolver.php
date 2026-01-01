<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Services;

use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;
use Relaticle\ImportWizard\Filament\Imports\OpportunityImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;

final class ImportRecordResolver
{
    /**
     * @var array{
     *     people: array{byId: array<int|string, People>, byEmail: array<string, People>},
     *     companies: array{byId: array<int|string, Company>, byDomain: array<string, Company>},
     *     opportunities: array{byId: array<int|string, Opportunity>}
     * }
     */
    private array $cache = [
        'people' => ['byId' => [], 'byEmail' => []],
        'companies' => ['byId' => [], 'byDomain' => []],
        'opportunities' => ['byId' => []],
    ];

    private ?string $cachedTeamId = null;

    /** @param class-string $importerClass */
    public function loadForTeam(string $teamId, string $importerClass): void
    {
        // Skip if already loaded for this team
        if ($this->cachedTeamId === $teamId) {
            return;
        }

        $this->cachedTeamId = $teamId;
        $this->cache = [
            'people' => ['byId' => [], 'byEmail' => []],
            'companies' => ['byId' => [], 'byDomain' => []],
            'opportunities' => ['byId' => []],
        ];

        // Load records based on importer type
        match ($importerClass) {
            PeopleImporter::class => $this->loadPeople($teamId),
            CompanyImporter::class => $this->loadCompanies($teamId),
            OpportunityImporter::class => $this->loadOpportunities($teamId),
            default => null,
        };
    }

    /** @param array<string> $emails */
    public function resolvePeopleByEmail(array $emails, string $teamId): ?People
    {
        $this->ensureCacheLoaded($teamId);

        foreach ($emails as $email) {
            $email = strtolower(trim($email));
            if (isset($this->cache['people']['byEmail'][$email])) {
                return $this->cache['people']['byEmail'][$email];
            }
        }

        return null;
    }

    public function resolveCompanyByDomain(string $domain, string $teamId): ?Company
    {
        $this->ensureCacheLoaded($teamId);

        $domain = strtolower(trim($domain));

        return $this->cache['companies']['byDomain'][$domain] ?? null;
    }

    private function loadPeople(string $teamId): void
    {
        // Query 1: Get emails custom field ID
        // Uses 'people' morph alias (from Relation::enforceMorphMap) instead of People::class
        $emailsField = CustomField::withoutGlobalScopes()
            ->where('code', PeopleField::EMAILS->value)
            ->where('entity_type', 'people')
            ->where('tenant_id', $teamId)
            ->first();

        if (! $emailsField) {
            return;
        }

        // Query 2: Load ALL people with email custom field values
        $people = People::query()
            ->where('team_id', $teamId)
            ->with(['customFieldValues' => function (\Illuminate\Database\Eloquent\Relations\Relation $query) use ($emailsField): void {
                $query->withoutGlobalScopes()
                    ->where('custom_field_id', $emailsField->id);
            }])
            ->get();

        // Build indexes
        foreach ($people as $person) {
            // Index by ID (cast to string to match array type)
            $this->cache['people']['byId'][(string) $person->id] = $person;

            // Index by each email (lowercase for case-insensitive matching)
            $emailCustomFieldValue = $person->customFieldValues->first();
            if ($emailCustomFieldValue) {
                $emails = $emailCustomFieldValue->json_value ?? [];
                foreach ($emails as $email) {
                    $email = strtolower(trim((string) $email));
                    // First match wins (same as current behavior)
                    if ($email !== '' && ! isset($this->cache['people']['byEmail'][$email])) {
                        $this->cache['people']['byEmail'][$email] = $person;
                    }
                }
            }
        }
    }

    private function loadCompanies(string $teamId): void
    {
        // Query 1: Get domains custom field ID
        // Uses 'company' morph alias (from Relation::enforceMorphMap) instead of Company::class
        $domainField = CustomField::withoutGlobalScopes()
            ->where('code', CompanyField::DOMAINS->value)
            ->where('entity_type', 'company')
            ->where('tenant_id', $teamId)
            ->first();

        // Query 2: Load ALL companies with domain custom field values if field exists
        $query = Company::query()->where('team_id', $teamId);

        if ($domainField) {
            $query->with(['customFieldValues' => function (\Illuminate\Database\Eloquent\Relations\Relation $q) use ($domainField): void {
                $q->withoutGlobalScopes()
                    ->where('custom_field_id', $domainField->id);
            }]);
        }

        $companies = $query->get();

        // Build indexes
        foreach ($companies as $company) {
            // Index by ID (cast to string to match array type)
            $this->cache['companies']['byId'][(string) $company->id] = $company;

            // Index by domains custom field (if field exists) - json_value is cast to Collection
            if ($domainField) {
                $domainValue = $company->customFieldValues->first();
                if ($domainValue === null) {
                    continue;
                }

                foreach ($domainValue->json_value ?? collect() as $domain) {
                    $domain = strtolower(trim((string) $domain));
                    if ($domain === '') {
                        continue;
                    }
                    // First match wins (for consistent behavior)
                    $this->cache['companies']['byDomain'][$domain] ??= $company;
                }
            }
        }
    }

    private function loadOpportunities(string $teamId): void
    {
        // Query: Load ALL opportunities
        $opportunities = Opportunity::query()
            ->where('team_id', $teamId)
            ->get();

        // Build indexes
        foreach ($opportunities as $opportunity) {
            // Index by ID (cast to string to match array type)
            $this->cache['opportunities']['byId'][(string) $opportunity->id] = $opportunity;
        }
    }

    private function ensureCacheLoaded(string $teamId): void
    {
        if ($this->cachedTeamId !== $teamId) {
            throw new \RuntimeException(
                "ImportRecordResolver not loaded for team {$teamId}. Call loadForTeam() first."
            );
        }
    }
}
