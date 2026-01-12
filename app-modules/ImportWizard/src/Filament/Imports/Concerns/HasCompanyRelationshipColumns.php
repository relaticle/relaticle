<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports\Concerns;

use App\Enums\CreationSource;
use App\Models\Company;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Support\CompanyMatcher;

/**
 * Provides relationship columns for entities that link to companies.
 *
 * These columns are automatically hidden from the regular field dropdown
 * (via `hiddenRelationshipColumns`) and shown under "Link to Records" instead.
 *
 * The column names MUST match the pattern: rel_{relationshipName}_{matcherKey}
 */
trait HasCompanyRelationshipColumns
{
    /**
     * Build ImportColumns for company relationship.
     *
     * @return array<ImportColumn>
     */
    protected static function buildCompanyRelationshipColumns(): array
    {
        return [
            // Match company by exact ID (ULID)
            ImportColumn::make('rel_company_id')
                ->label('Company Record ID')
                ->guess(['company_id', 'company_record_id'])
                ->rules(['nullable', 'string', 'ulid'])
                ->example('01HQWX...')
                ->fillRecordUsing(function (Model $record, ?string $state, Importer $importer): void {
                    if (blank($state) || ! $importer->import->team_id) {
                        return;
                    }

                    $companyId = trim($state);

                    if (! Str::isUlid($companyId)) {
                        return;
                    }

                    // Verify company exists in current team
                    $company = Company::query()
                        ->where('id', $companyId)
                        ->where('team_id', $importer->import->team_id)
                        ->first();

                    if ($company instanceof Company) {
                        $record->setAttribute('company_id', $company->getKey());
                    }
                }),

            // Match company by domain (extracted from email or explicit)
            ImportColumn::make('rel_company_domain')
                ->label('Company Domain')
                ->guess(['company_domain', 'domain'])
                ->rules(['nullable', 'string'])
                ->example('acme.com')
                ->fillRecordUsing(function (Model $record, ?string $state, Importer $importer): void {
                    // Skip if already resolved by ID
                    if (filled($record->getAttribute('company_id'))) {
                        return;
                    }

                    if (blank($state) || ! $importer->import->team_id) {
                        return;
                    }

                    $domain = trim($state);

                    // Extract domain if full email provided
                    if (filter_var($domain, FILTER_VALIDATE_EMAIL)) {
                        $domain = substr(strrchr($domain, '@') ?: '', 1);
                    }

                    if ($domain === '') {
                        return;
                    }

                    // Try domain-based matching
                    // CompanyMatcher expects emails in the third param, so construct a fake email for domain matching
                    // Explicit domain mapping bypasses public email filtering (user intent is clear)
                    $emailForMatcher = str_contains($domain, '@') ? $domain : "match@{$domain}";
                    $matcher = resolve(CompanyMatcher::class);
                    $result = $matcher->match('', '', [$emailForMatcher], $importer->import->team_id, filterPublicDomains: false);

                    if ($result->isDomainMatch() && $result->companyId !== null) {
                        $record->setAttribute('company_id', $result->companyId);
                    }
                }),

            // Create company by name (after automatic domain matching attempt)
            ImportColumn::make('rel_company_name')
                ->label('Company Name')
                ->guess([
                    'company_name', 'Company',
                    'company', 'employer', 'organization', 'organisation', 'works_at',
                    'associated company', 'account', 'account_name', 'business',
                ])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (Model $record, ?string $state, Importer $importer): void {
                    // Skip if already resolved by ID or explicit domain
                    if (filled($record->getAttribute('company_id'))) {
                        return;
                    }

                    if (blank($state)) {
                        return;
                    }

                    throw_unless($importer->import->team_id, \RuntimeException::class, 'Team ID is required for import');

                    $companyName = trim($state);
                    if ($companyName === '') {
                        return;
                    }

                    // Automatic domain matching: Try to match by email domain before creating
                    // This happens invisibly when person has emails mapped
                    $emails = self::extractEmailsFromImporter($importer);
                    if ($emails !== []) {
                        $matcher = resolve(CompanyMatcher::class);
                        $result = $matcher->match('', '', $emails, $importer->import->team_id);

                        if ($result->isDomainMatch() && $result->companyId !== null) {
                            $record->setAttribute('company_id', $result->companyId);

                            return;
                        }
                    }

                    // No domain match found - create new company by name
                    $company = Company::query()->create([
                        'name' => $companyName,
                        'team_id' => $importer->import->team_id,
                        'creator_id' => $importer->import->user_id,
                        'creation_source' => CreationSource::IMPORT,
                    ]);

                    $record->setAttribute('company_id', $company->getKey());
                }),
        ];
    }

    /**
     * Extract emails from the importer's current row data.
     *
     * Checks for custom_fields_emails which is the standard emails field.
     *
     * @return array<int, string>
     */
    private static function extractEmailsFromImporter(Importer $importer): array
    {
        $emailsField = $importer->data['custom_fields_emails'] ?? null;

        if (blank($emailsField)) {
            return [];
        }

        $emails = is_string($emailsField)
            ? explode(',', $emailsField)
            : (array) $emailsField;

        return collect($emails)
            ->map(fn (mixed $email): string => trim((string) $email))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->values()
            ->all();
    }
}
