<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Enums\CreationSource;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\People;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Services\CompanyMatcher;

final class PeopleImporter extends BaseImporter
{
    protected static ?string $model = People::class;

    protected static array $uniqueIdentifierColumns = ['id', 'custom_fields_emails'];

    protected static string $missingUniqueIdentifiersMessage = 'For People, map an Email addresses or Record ID column';

    public static function getColumns(): array
    {
        return [
            self::buildIdColumn(),

            ImportColumn::make('name')
                ->label('Name')
                ->requiredMapping()
                ->guess(['name', 'full_name', 'person_name'])
                ->rules(['required', 'string', 'max:255'])
                ->example('John Doe')
                ->fillRecordUsing(function (People $record, string $state, PeopleImporter $importer): void {
                    $record->name = $state;
                    $importer->initializeNewRecord($record);
                }),

            ImportColumn::make('company_id')
                ->label('Company Record ID')
                ->guess(['company_id', 'company_record_id'])
                ->rules(['nullable', 'string', 'ulid'])
                ->example('01HQWX...')
                ->fillRecordUsing(function (People $record, ?string $state, PeopleImporter $importer): void {
                    if (blank($state) || ! $importer->import->team_id) {
                        return;
                    }

                    $companyId = trim($state);

                    // Verify company exists in current team
                    $company = Company::query()
                        ->where('id', $companyId)
                        ->where('team_id', $importer->import->team_id)
                        ->first();

                    if ($company) {
                        $record->company_id = $company->getKey();
                    }
                }),

            ImportColumn::make('company_name')
                ->label('Company Name')
                ->guess(['company_name', 'Company'])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (People $record, ?string $state, PeopleImporter $importer): void {
                    // Skip if company already set by company_id column
                    if ($record->company_id) {
                        return;
                    }

                    if (! $importer->import->team_id) {
                        throw new \RuntimeException('Team ID is required for import');
                    }

                    $companyName = $state !== null ? trim($state) : '';
                    $emails = $importer->extractEmails();

                    // Try domain-based matching first when company_name is empty but emails exist
                    if ($companyName === '' && $emails !== []) {
                        $matcher = app(CompanyMatcher::class);
                        $result = $matcher->match('', '', $emails, $importer->import->team_id);

                        if ($result->isDomainMatch() && $result->companyId !== null) {
                            $record->company_id = $result->companyId;

                            return;
                        }
                    }

                    // No company to link - person will have no company
                    if ($companyName === '') {
                        return;
                    }

                    // Find or create company by name (prevents duplicates within import)
                    try {
                        $company = Company::firstOrCreate(
                            [
                                'name' => $companyName,
                                'team_id' => $importer->import->team_id,
                            ],
                            [
                                'creator_id' => $importer->import->user_id,
                                'creation_source' => CreationSource::IMPORT,
                            ]
                        );

                        $record->company_id = $company->getKey();
                    } catch (\Exception $e) {
                        report($e);
                        throw $e;
                    }
                }),

            ...CustomFields::importer()->forModel(self::getModel())->columns(),
        ];
    }

    public function resolveRecord(): People
    {
        // ID-based resolution takes absolute precedence
        if ($this->hasIdValue()) {
            /** @var People|null $record */
            $record = $this->resolveById();

            return $record ?? new People;
        }

        // Fall back to email-based duplicate detection
        $existing = $this->findByEmail();

        /** @var People */
        return $this->applyDuplicateStrategy($existing);
    }

    private function findByEmail(): ?People
    {
        $emails = $this->extractEmails();

        if ($emails === []) {
            return null;
        }

        // Security: Always require team_id for proper tenant isolation
        if (! $this->import->team_id) {
            return null;
        }

        // Fast path: Use pre-loaded resolver (preview mode)
        if ($this->hasRecordResolver()) {
            return $this->getRecordResolver()->resolvePeopleByEmail(
                $emails,
                $this->import->team_id
            );
        }

        // Slow path: Query database (actual import execution)
        // Find the emails custom field for this team
        // Uses 'people' morph alias (from Relation::enforceMorphMap) instead of People::class
        $emailsField = CustomField::withoutGlobalScopes()
            ->where('code', PeopleField::EMAILS->value)
            ->where('entity_type', 'people')
            ->where('tenant_id', $this->import->team_id)
            ->first();

        if (! $emailsField) {
            return null;
        }

        // Get the correct value column for this field type
        $valueColumn = $emailsField->getValueColumn();

        return People::query()
            ->where('team_id', $this->import->team_id)
            ->whereHas('customFieldValues', function (Builder $query) use ($emails, $emailsField, $valueColumn): void {
                $query->withoutGlobalScopes()
                    ->where('custom_field_id', $emailsField->id)
                    ->where('tenant_id', $this->import->team_id)
                    ->where(function (Builder $query) use ($emails, $valueColumn): void {
                        foreach ($emails as $email) {
                            // For json_value (collection type), need to check if array contains email
                            // For other value types, use direct match
                            if ($valueColumn === 'json_value') {
                                // SQLite-compatible JSON search
                                // JSON value is stored as: ["email@example.com"]
                                // So we search for: "email@example.com" (with quotes)
                                $query->orWhere($valueColumn, 'LIKE', '%"'.str_replace('"', '\"', $email).'"%');
                            } else {
                                $query->orWhere($valueColumn, $email);
                            }
                        }
                    });
            })
            ->first();
    }

    /**
     * Extract and validate emails from import data
     *
     * @return array<int, string>
     */
    public function extractEmails(): array
    {
        $emailsField = $this->data['custom_fields_emails'] ?? null;

        if (empty($emailsField)) {
            return [];
        }

        $emails = is_string($emailsField)
            ? explode(',', $emailsField)
            : (array) $emailsField;

        return collect($emails)
            ->map(fn (mixed $email): string => trim((string) $email))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->values()
            ->toArray();
    }

    public static function getEntityName(): string
    {
        return 'people';
    }
}
