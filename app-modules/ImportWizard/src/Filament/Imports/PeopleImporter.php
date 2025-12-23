<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Enums\CreationSource;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\People;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\CustomFields\Models\CustomField;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;

final class PeopleImporter extends BaseImporter
{
    protected static ?string $model = People::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('id')
                ->label('Record ID')
                ->guess(['id', 'record_id', 'ulid', 'record id'])
                ->rules(['nullable', 'ulid'])
                ->example('01KCCFMZ52QWZSQZWVG0AP704V')
                ->helperText('Include existing record IDs to update specific records. Leave empty to create new records.'),

            ImportColumn::make('name')
                ->label('Name')
                ->requiredMapping()
                ->guess(['name', 'full_name', 'person_name'])
                ->rules(['required', 'string', 'max:255'])
                ->example('John Doe')
                ->fillRecordUsing(function (People $record, string $state, Importer $importer): void {
                    $record->name = $state;

                    // Set team and creator for new records
                    if (! $record->exists) {
                        $record->team_id = $importer->import->team_id;
                        $record->creator_id = $importer->import->user_id;
                        $record->creation_source = CreationSource::IMPORT;
                    }
                }),

            ImportColumn::make('company_name')
                ->requiredMapping()
                ->label('Company Name')
                ->guess(['company_name', 'Company'])
                ->rules(['required', 'string', 'max:255'])
                ->example('Acme Corporation')
                ->fillRecordUsing(function (People $record, string $state, Importer $importer): void {
                    // Since company_name is required, we should always have a value
                    if (! $importer->import->team_id) {
                        throw new \RuntimeException('Team ID is required for import');
                    }

                    try {
                        $company = Company::firstOrCreate(
                            [
                                'name' => trim($state),
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
                        throw $e; // Re-throw to fail the import for this row
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

        $strategy = $this->getDuplicateStrategy();

        return match ($strategy) {
            DuplicateHandlingStrategy::SKIP, DuplicateHandlingStrategy::UPDATE => $existing ?? new People,
            DuplicateHandlingStrategy::CREATE_NEW => new People,
        };
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
        $emailsField = CustomField::withoutGlobalScopes()
            ->where('code', PeopleField::EMAILS->value)
            ->where('entity_type', People::class)
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
    private function extractEmails(): array
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

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your people import has completed and '.Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if (($failedRowsCount = $import->getFailedRowsCount()) !== 0) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    public static function getUniqueIdentifierColumns(): array
    {
        return ['id', 'custom_fields_emails'];
    }

    public static function getMissingUniqueIdentifiersMessage(): string
    {
        return 'For People, map an Email addresses or Record ID column';
    }
}
