<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports\Concerns;

use App\Enums\CreationSource;
use App\Enums\CustomFields\PeopleField;
use App\Models\CustomField;
use App\Models\People;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Provides relationship columns for entities that link to contacts (people).
 *
 * These columns are automatically hidden from the regular field dropdown
 * (via `hiddenRelationshipColumns`) and shown under "Link to Records" instead.
 *
 * The column names MUST match the pattern: rel_{relationshipName}_{matcherKey}
 */
trait HasContactRelationshipColumns
{
    /**
     * Build ImportColumns for contact relationship.
     *
     * @return array<ImportColumn>
     */
    protected static function buildContactRelationshipColumns(): array
    {
        return [
            // Match contact by exact ID (ULID)
            ImportColumn::make('rel_contact_id')
                ->label('Contact Record ID')
                ->guess(['contact_id', 'person_id', 'people_id'])
                ->rules(['nullable', 'string', 'ulid'])
                ->example('01HQWX...')
                ->fillRecordUsing(function (Model $record, ?string $state, Importer $importer): void {
                    if (blank($state) || ! $importer->import->team_id) {
                        return;
                    }

                    $contactId = trim($state);

                    if (! Str::isUlid($contactId)) {
                        return;
                    }

                    // Verify contact exists in current team
                    $contact = People::query()
                        ->where('id', $contactId)
                        ->where('team_id', $importer->import->team_id)
                        ->first();

                    if ($contact instanceof People) {
                        $record->setAttribute('contact_id', $contact->getKey());
                    }
                }),

            // Match contact by email (email is unique identifier for people)
            ImportColumn::make('rel_contact_email')
                ->label('Contact Email')
                ->guess(['contact_email', 'person_email'])
                ->rules(['nullable', 'email'])
                ->example('john@acme.com')
                ->fillRecordUsing(function (Model $record, ?string $state, Importer $importer): void {
                    // Skip if already resolved by ID
                    if (filled($record->getAttribute('contact_id'))) {
                        return;
                    }

                    if (blank($state) || ! $importer->import->team_id) {
                        return;
                    }

                    $email = strtolower(trim($state));
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        return;
                    }

                    // Find the emails custom field for this team
                    $emailsField = CustomField::query()->withoutGlobalScopes()
                        ->where('code', PeopleField::EMAILS->value)
                        ->where('entity_type', 'people')
                        ->where('tenant_id', $importer->import->team_id)
                        ->first();

                    if (! $emailsField) {
                        return;
                    }

                    $valueColumn = $emailsField->getValueColumn();

                    // Find person by email
                    $contact = People::query()
                        ->where('team_id', $importer->import->team_id)
                        ->whereHas('customFieldValues', function (Builder $query) use ($email, $emailsField, $valueColumn, $importer): void {
                            $query->withoutGlobalScopes()
                                ->where('custom_field_id', $emailsField->id)
                                ->where('tenant_id', $importer->import->team_id)
                                ->where(function (Builder $query) use ($email, $valueColumn): void {
                                    if ($valueColumn === 'json_value') {
                                        $query->where($valueColumn, 'LIKE', '%"'.str_replace('"', '\"', $email).'"%');
                                    } else {
                                        $query->where($valueColumn, $email);
                                    }
                                });
                        })
                        ->first();

                    if ($contact instanceof People) {
                        $record->setAttribute('contact_id', $contact->getKey());
                    }
                }),

            // Create contact by name (name is not unique, always creates new)
            ImportColumn::make('rel_contact_name')
                ->label('Contact Name')
                ->guess([
                    'contact_name', 'contact', 'person',
                    'contact name', 'primary contact', 'main contact', 'lead',
                    'prospect', 'decision maker', 'buyer',
                ])
                ->rules(['nullable', 'string', 'max:255'])
                ->example('John Doe')
                ->fillRecordUsing(function (Model $record, ?string $state, Importer $importer): void {
                    // Skip if already resolved by ID or email
                    if (filled($record->getAttribute('contact_id'))) {
                        return;
                    }

                    if (blank($state)) {
                        return;
                    }

                    throw_unless($importer->import->team_id, \RuntimeException::class, 'Team ID is required for import');

                    $contactName = trim($state);
                    if ($contactName === '') {
                        return;
                    }

                    // Create new contact (name is not unique, so no matching)
                    $contact = People::query()->create([
                        'name' => $contactName,
                        'team_id' => $importer->import->team_id,
                        'creator_id' => $importer->import->user_id,
                        'creation_source' => CreationSource::IMPORT,
                    ]);

                    $record->setAttribute('contact_id', $contact->getKey());
                }),
        ];
    }
}
