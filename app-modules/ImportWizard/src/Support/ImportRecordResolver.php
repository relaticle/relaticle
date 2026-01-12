<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use Relaticle\ImportWizard\Filament\Imports\CompanyImporter;
use Relaticle\ImportWizard\Filament\Imports\PeopleImporter;

final class ImportRecordResolver
{
    /**
     * @var array{
     *     people: array{byId: array<int|string, People>, byEmail: array<string, People>, byPhone: array<string, People>},
     *     companies: array{byId: array<int|string, Company>, byDomain: array<string, Company>}
     * }
     */
    private array $cache = [
        'people' => ['byId' => [], 'byEmail' => [], 'byPhone' => []],
        'companies' => ['byId' => [], 'byDomain' => []],
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
            'people' => ['byId' => [], 'byEmail' => [], 'byPhone' => []],
            'companies' => ['byId' => [], 'byDomain' => []],
        ];

        // Load records based on importer type
        match ($importerClass) {
            PeopleImporter::class => $this->loadPeople($teamId),
            CompanyImporter::class => $this->loadCompanies($teamId),
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

    public function resolvePeopleByPhone(string $phone, string $teamId): ?People
    {
        $this->ensureCacheLoaded($teamId);

        $phone = $this->normalizePhoneForMatching($phone);

        return $this->cache['people']['byPhone'][$phone] ?? null;
    }

    /**
     * Normalize phone number for matching by stripping all non-digits except leading +.
     */
    private function normalizePhoneForMatching(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone) ?? '';
    }

    public function resolveCompanyByDomain(string $domain, string $teamId): ?Company
    {
        $this->ensureCacheLoaded($teamId);

        $domain = strtolower(trim($domain));

        return $this->cache['companies']['byDomain'][$domain] ?? null;
    }

    private function loadPeople(string $teamId): void
    {
        // Get unique identifier custom fields
        // Uses 'people' morph alias (from Relation::enforceMorphMap) instead of People::class
        $emailsField = CustomField::query()->withoutGlobalScopes()
            ->where('code', PeopleField::EMAILS->value)
            ->where('entity_type', 'people')
            ->where('tenant_id', $teamId)
            ->first();

        $phoneField = CustomField::query()->withoutGlobalScopes()
            ->where('code', PeopleField::PHONE_NUMBER->value)
            ->where('entity_type', 'people')
            ->where('tenant_id', $teamId)
            ->first();

        // Need at least one identifier field
        if (! $emailsField && ! $phoneField) {
            return;
        }

        // Build list of custom field IDs to load
        $fieldIds = array_filter([
            $emailsField?->id,
            $phoneField?->id,
        ]);

        // Load ALL people with their identifier custom field values
        $people = People::query()
            ->where('team_id', $teamId)
            ->with(['customFieldValues' => function (\Illuminate\Database\Eloquent\Relations\Relation $query) use ($fieldIds): void {
                $query->withoutGlobalScopes()
                    ->whereIn('custom_field_id', $fieldIds);
            }])
            ->get();

        // Build indexes
        foreach ($people as $person) {
            // Index by ID (cast to string to match array type)
            $this->cache['people']['byId'][(string) $person->id] = $person;

            // Index by email (if field exists)
            if ($emailsField) {
                $emailValue = $person->customFieldValues->firstWhere('custom_field_id', $emailsField->id);
                if ($emailValue) {
                    $emails = $emailValue->json_value ?? [];
                    foreach ($emails as $email) {
                        $email = strtolower(trim((string) $email));
                        // First match wins (same as current behavior)
                        if ($email !== '' && ! isset($this->cache['people']['byEmail'][$email])) {
                            $this->cache['people']['byEmail'][$email] = $person;
                        }
                    }
                }
            }

            // Index by phone (if field exists)
            if ($phoneField) {
                $phoneValue = $person->customFieldValues->firstWhere('custom_field_id', $phoneField->id);
                if ($phoneValue) {
                    // Phone is stored as string_value (single value) in E.164 format
                    $phone = $phoneValue->string_value;
                    if (filled($phone)) {
                        $normalizedPhone = $this->normalizePhoneForMatching((string) $phone);
                        if ($normalizedPhone !== '' && ! isset($this->cache['people']['byPhone'][$normalizedPhone])) {
                            $this->cache['people']['byPhone'][$normalizedPhone] = $person;
                        }
                    }
                }
            }
        }
    }

    private function loadCompanies(string $teamId): void
    {
        // Query 1: Get domains custom field ID
        // Uses 'company' morph alias (from Relation::enforceMorphMap) instead of Company::class
        $domainField = CustomField::query()->withoutGlobalScopes()
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

    private function ensureCacheLoaded(string $teamId): void
    {
        throw_if($this->cachedTeamId !== $teamId, \RuntimeException::class, "ImportRecordResolver not loaded for team {$teamId}. Call loadForTeam() first.");
    }
}
