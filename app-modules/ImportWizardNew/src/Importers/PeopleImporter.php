<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Importers;

use App\Models\Company;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizardNew\Data\MatchableField;
use Relaticle\ImportWizardNew\Importers\Fields\FieldCollection;
use Relaticle\ImportWizardNew\Importers\Fields\ImportField;
use Relaticle\ImportWizardNew\Importers\Fields\RelationshipField;

/**
 * Importer for People entities.
 *
 * People can be matched by email or phone, and linked to companies.
 */
final class PeopleImporter extends BaseImporter
{
    public function modelClass(): string
    {
        return People::class;
    }

    public function entityName(): string
    {
        return 'people';
    }

    public function fields(): FieldCollection
    {
        return FieldCollection::make([
            ImportField::id(),

            ImportField::make('name')
                ->label('Name')
                ->required()
                ->rules(['required', 'string', 'max:255'])
                ->guess([
                    'name', 'full_name', 'person_name',
                    'contact', 'contact_name', 'person', 'individual', 'member', 'employee',
                    'full name', 'display_name', 'displayname',
                    'contact name', 'lead name', 'prospect name',
                ])
                ->example('John Doe')
                ->type('text'),
        ]);
    }

    /**
     * @return array<string, RelationshipField>
     */
    public function relationships(): array
    {
        return [
            'company' => RelationshipField::company(),
        ];
    }

    /**
     * @return array<MatchableField>
     */
    public function matchableFields(): array
    {
        return [
            MatchableField::id(),
            MatchableField::email('custom_fields_emails'),
            MatchableField::phone('custom_fields_phone_number'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function prepareForSave(array $data, ?Model $existing, array $context): array
    {
        $data = parent::prepareForSave($data, $existing, $context);

        $data = $this->resolveCompanyRelationship($data, $context);

        if (! $existing instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->initializeNewRecordData($data, $context['creator_id'] ?? null);
        }

        return $data;
    }

    /**
     * Resolve company relationship from import data.
     *
     * Uses the highest priority match field that was mapped.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveCompanyRelationship(array $data, array $context): array
    {
        $companyValue = $data['company'] ?? null;
        unset($data['company']);

        if (blank($companyValue)) {
            return $data;
        }

        $matchField = $context['company_match_field'] ?? 'name';

        $companyId = $this->resolveBelongsTo(
            Company::class,
            $matchField,
            $companyValue,
        );

        if ($companyId !== null) {
            $data['company_id'] = $companyId;
        }

        return $data;
    }
}
