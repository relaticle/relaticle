<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Models\CustomField;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\CustomFields\Facades\CustomFields;

final class CompanyImporter extends BaseImporter
{
    protected static ?string $model = Company::class;

    protected static array $uniqueIdentifierColumns = ['id'];

    protected static string $missingUniqueIdentifiersMessage = 'For Companies, map a Record ID column';

    public static function getColumns(): array
    {
        return [
            self::buildIdColumn(),

            ImportColumn::make('name')
                ->label('Name')
                ->requiredMapping()
                ->guess([
                    'name', 'company_name', 'company', 'organization', 'account', 'account_name',
                    'company name', 'associated company', 'company domain name',
                    'account name', 'parent account', 'billing name',
                    'business', 'business_name', 'org', 'org_name', 'organisation',
                    'firm', 'client', 'customer', 'customer_name', 'vendor', 'vendor_name',
                ])
                ->rules(['required', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (Company $record, string $state, CompanyImporter $importer): void {
                    $record->name = trim($state);
                    $importer->initializeNewRecord($record);
                }),

            ImportColumn::make('account_owner_email')
                ->label('Account Owner Email')
                ->guess([
                    'account_owner', 'owner_email', 'owner', 'assigned_to', 'account_manager',
                    'owner email', 'sales rep', 'sales_rep', 'rep', 'salesperson', 'sales_owner',
                    'account_rep', 'assigned_user', 'manager_email', 'contact_owner',
                ])
                ->rules(['nullable', 'email'])
                ->example('owner@company.com')
                ->fillRecordUsing(function (Company $record, ?string $state, Importer $importer): void {
                    if (blank($state)) {
                        return;
                    }

                    /** @var BaseImporter $importer */
                    $user = $importer->resolveTeamMemberByEmail($state);

                    if ($user !== null) {
                        $record->account_owner_id = $user->getKey();
                    }
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): Company
    {
        // ID-based resolution takes absolute precedence
        if ($this->hasIdValue()) {
            /** @var Company|null $record */
            $record = $this->resolveById();

            return $record ?? new Company;
        }

        // Try domain-based duplicate detection
        $domainsFieldKey = 'custom_fields_'.CompanyField::DOMAINS->value;
        $domainValue = $this->data[$domainsFieldKey] ?? null;
        $domain = $this->extractFirstDomain($domainValue);

        if ($domain !== null) {
            $existing = $this->findByDomain($domain);
            if ($existing instanceof Company) {
                /** @var Company */
                return $this->applyDuplicateStrategy($existing);
            }
        }

        // No match found - create new company
        return new Company;
    }

    /**
     * Find company by domains custom field.
     */
    private function findByDomain(string $domain): ?Company
    {
        $domain = strtolower($domain);

        // Fast path: Use pre-loaded resolver (preview mode)
        if ($this->hasRecordResolver()) {
            return $this->getRecordResolver()->resolveCompanyByDomain(
                $domain,
                $this->import->team_id
            );
        }

        // Slow path: Query database (actual import execution)
        // Uses 'company' morph alias (from Relation::enforceMorphMap) instead of Company::class
        $domainField = CustomField::withoutGlobalScopes()
            ->where('code', CompanyField::DOMAINS->value)
            ->where('entity_type', 'company')
            ->where('tenant_id', $this->import->team_id)
            ->first();

        if (! $domainField) {
            return null;
        }

        // Search in json_value array for matching domain
        return Company::query()
            ->where('team_id', $this->import->team_id)
            ->whereHas('customFieldValues', function (Builder $query) use ($domainField, $domain): void {
                $query->withoutGlobalScopes()
                    ->where('custom_field_id', $domainField->id)
                    ->where('tenant_id', $this->import->team_id)
                    ->whereJsonContains('json_value', $domain);
            })
            ->first();
    }

    /**
     * Extract the first domain from various input formats.
     * Handles: string, comma-separated string, array (after cast).
     */
    private function extractFirstDomain(mixed $value): ?string
    {
        if (in_array($value, [null, '', []], true)) {
            return null;
        }

        // Already an array (after castData processing)
        if (is_array($value)) {
            $first = $value[0] ?? null;

            return is_string($first) && $first !== '' ? strtolower(trim($first)) : null;
        }

        // String - could be single or comma-separated
        if (is_string($value)) {
            $parts = explode(',', $value);
            $first = trim($parts[0]);

            return $first !== '' ? strtolower($first) : null;
        }

        return null;
    }

    public static function getEntityName(): string
    {
        return 'company';
    }
}
