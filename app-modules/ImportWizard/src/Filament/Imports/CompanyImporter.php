<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Models\Company;
use App\Models\CustomField;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\CustomFields\Facades\CustomFields;

final class CompanyImporter extends BaseImporter
{
    protected static ?string $model = Company::class;

    protected static array $uniqueIdentifierColumns = ['id', 'name'];

    protected static string $missingUniqueIdentifiersMessage = 'For Companies, map a Company name or Record ID column';

    public static function getColumns(): array
    {
        return [
            self::buildIdColumn(),

            ImportColumn::make('name')
                ->label('Name')
                ->requiredMapping()
                ->guess(['name', 'company_name', 'company', 'organization', 'account', 'account_name'])
                ->rules(['required', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (Company $record, string $state, CompanyImporter $importer): void {
                    $record->name = trim($state);
                    $importer->initializeNewRecord($record);
                }),

            ImportColumn::make('account_owner_email')
                ->label('Account Owner Email')
                ->guess(['account_owner', 'owner_email', 'owner', 'assigned_to', 'account_manager'])
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

        // Step 1: Try domain-based duplicate detection (highest confidence for uniqueness)
        // Domain field is array type but imported as string or comma-separated
        $domainValue = $this->data['custom_fields_domain_name'] ?? null;
        $domain = $this->extractFirstDomain($domainValue);

        if ($domain !== null) {
            $existing = $this->findByDomain($domain);
            if ($existing instanceof \App\Models\Company) {
                /** @var Company */
                return $this->applyDuplicateStrategy($existing);
            }
        }

        // Step 2: Fall back to name-based duplicate detection
        $name = $this->data['name'] ?? null;

        if (blank($name)) {
            return new Company;
        }

        // Fast path: Use pre-loaded resolver (preview mode)
        if ($this->hasRecordResolver()) {
            $existing = $this->getRecordResolver()->resolveCompanyByName(
                trim((string) $name),
                $this->import->team_id
            );
        } else {
            // Slow path: Query database (actual import execution)
            $existing = Company::query()
                ->where('team_id', $this->import->team_id)
                ->where('name', trim((string) $name))
                ->first();
        }

        /** @var Company */
        return $this->applyDuplicateStrategy($existing);
    }

    /**
     * Find company by domain_name custom field.
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
            ->where('code', 'domain_name')
            ->where('entity_type', 'company')
            ->where('tenant_id', $this->import->team_id)
            ->first();

        if (! $domainField) {
            return null;
        }

        return Company::query()
            ->where('team_id', $this->import->team_id)
            ->whereHas('customFieldValues', function (Builder $query) use ($domainField, $domain): void {
                $query->withoutGlobalScopes()
                    ->where('custom_field_id', $domainField->id)
                    ->where('tenant_id', $this->import->team_id)
                    ->whereRaw('LOWER(string_value) = ?', [$domain]);
            })
            ->first();
    }

    /**
     * Extract the first domain from various input formats.
     * Handles: string, comma-separated string, array (after cast).
     */
    private function extractFirstDomain(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
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
            $first = trim($parts[0] ?? '');

            return $first !== '' ? strtolower($first) : null;
        }

        return null;
    }

    public static function getEntityName(): string
    {
        return 'company';
    }
}
