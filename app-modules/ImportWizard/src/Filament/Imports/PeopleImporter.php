<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\People;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;

final class PeopleImporter extends BaseImporter
{
    protected static ?string $model = People::class;

    public static function getColumns(): array
    {
        return [
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
        $existing = $this->findByEmail();

        $strategy = $this->getDuplicateStrategy();

        return match ($strategy) {
            DuplicateHandlingStrategy::SKIP => $existing ?? new People,
            DuplicateHandlingStrategy::UPDATE => $existing ?? new People,
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

        return People::query()
            ->where('team_id', $this->import->team_id)
            ->whereHas('customFieldValues', function (Builder $query) use ($emails): void {
                $query->whereRelation('customField', 'code', 'emails')
                    ->where(function (Builder $query) use ($emails): void {
                        foreach ($emails as $email) {
                            $query->orWhereJsonContains('json_value', $email);
                        }
                    });
            })
            ->first();
    }

    /**
     * Extract and validate emails from original data
     *
     * @return array<int, string>
     */
    private function extractEmails(): array
    {
        $emailsField = $this->getOriginalData()['custom_fields_emails'] ?? null;

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
}
