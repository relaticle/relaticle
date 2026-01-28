<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Importers;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Data\MatchableField;

/**
 * Importer for Opportunity entities.
 *
 * Opportunities are linked to companies and contacts.
 * They can only be matched by ID (no attribute-based matching).
 */
final class OpportunityImporter extends BaseImporter
{
    public function modelClass(): string
    {
        return Opportunity::class;
    }

    public function entityName(): string
    {
        return 'opportunity';
    }

    public function fields(): ImportFieldCollection
    {
        return new ImportFieldCollection([
            ImportField::id(),

            ImportField::make('name')
                ->label('Name')
                ->required()
                ->rules(['required', 'string', 'max:255'])
                ->guess([
                    'name', 'opportunity_name', 'deal', 'deal_name',
                    'opportunity', 'title', 'subject',
                    'deal title', 'opportunity title', 'pipeline',
                ])
                ->example('Enterprise License Deal')
                ->icon('heroicon-o-currency-dollar'),
        ]);
    }

    /**
     * @return array<string, EntityLink>
     */
    protected function defineEntityLinks(): array
    {
        return [
            'company' => EntityLink::company(),
            'contact' => EntityLink::contact(),
        ];
    }

    /**
     * @return array<MatchableField>
     */
    public function matchableFields(): array
    {
        return [
            MatchableField::id(),
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
        $data = $this->resolveContactRelationship($data, $context);

        if (! $existing instanceof \Illuminate\Database\Eloquent\Model) {
            return $this->initializeNewRecordData($data, $context['creator_id'] ?? null);
        }

        return $data;
    }

    /**
     * Resolve company relationship from import data.
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

    /**
     * Resolve contact relationship from import data.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function resolveContactRelationship(array $data, array $context): array
    {
        $contactValue = $data['contact'] ?? null;
        unset($data['contact']);

        if (blank($contactValue)) {
            return $data;
        }

        $matchField = $context['contact_match_field'] ?? 'name';

        $contactId = $this->resolveBelongsTo(
            People::class,
            $matchField,
            $contactValue,
        );

        if ($contactId !== null) {
            $data['contact_id'] = $contactId;
        }

        return $data;
    }
}
