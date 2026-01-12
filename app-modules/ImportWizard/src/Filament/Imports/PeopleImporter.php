<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use Filament\Actions\Imports\ImportColumn;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Data\RelationshipField;
use Relaticle\ImportWizard\Filament\Imports\Concerns\HasCompanyRelationshipColumns;

final class PeopleImporter extends BaseImporter
{
    use HasCompanyRelationshipColumns;

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
                ->guess([
                    'name', 'full_name', 'person_name',
                    'contact', 'contact_name', 'person', 'individual', 'member', 'employee',
                    'full name', 'display_name', 'displayname',
                    'contact name', 'lead name', 'prospect name',
                ])
                ->rules(['required', 'string', 'max:255'])
                ->example('John Doe')
                ->fillRecordUsing(function (People $record, string $state, PeopleImporter $importer): void {
                    $record->name = $state;
                    $importer->initializeNewRecord($record);
                }),

            ...self::buildCompanyRelationshipColumns(),

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
        $emailsField = CustomField::query()->withoutGlobalScopes()
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

    public static function getEntityName(): string
    {
        return 'people';
    }

    /**
     * @return array<string, RelationshipField>
     */
    public static function getRelationshipFields(): array
    {
        return [
            'company' => RelationshipField::company(),
        ];
    }
}
