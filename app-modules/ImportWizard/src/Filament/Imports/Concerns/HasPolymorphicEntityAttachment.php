<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports\Concerns;

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;

/**
 * Provides polymorphic entity attachment for importers.
 *
 * Use this trait in importers that need to attach companies, people,
 * and opportunities to records via morphToMany relationships.
 *
 * The record must implement companies(), people(), and opportunities()
 * relationship methods that return MorphToMany instances.
 */
trait HasPolymorphicEntityAttachment
{
    /**
     * Attach related entities (company, person, opportunity) to the record.
     *
     * Creates entities if they don't exist, using firstOrCreate.
     * Uses syncWithoutDetaching to avoid removing existing relationships.
     */
    protected function attachRelatedEntities(): void
    {
        /** @var Model $record */
        $record = $this->record;
        $teamId = $this->import->team_id;
        $creatorId = $this->import->user_id;

        $this->attachCompanyIfProvided($record, $teamId, $creatorId);
        $this->attachPersonIfProvided($record, $teamId, $creatorId);
        $this->attachOpportunityIfProvided($record, $teamId, $creatorId);
    }

    /**
     * Attach a company to the record if company_name is provided.
     */
    private function attachCompanyIfProvided(Model $record, string $teamId, string $creatorId): void
    {
        $companyName = $this->data['company_name'] ?? null;

        if (blank($companyName)) {
            return;
        }

        $company = Company::firstOrCreate(
            ['name' => trim((string) $companyName), 'team_id' => $teamId],
            ['creator_id' => $creatorId, 'creation_source' => CreationSource::IMPORT]
        );

        /** @phpstan-ignore-next-line */
        $record->companies()->syncWithoutDetaching([$company->id]);
    }

    /**
     * Attach a person to the record if person_name is provided.
     */
    private function attachPersonIfProvided(Model $record, string $teamId, string $creatorId): void
    {
        $personName = $this->data['person_name'] ?? null;

        if (blank($personName)) {
            return;
        }

        $person = People::firstOrCreate(
            ['name' => trim((string) $personName), 'team_id' => $teamId],
            ['creator_id' => $creatorId, 'creation_source' => CreationSource::IMPORT]
        );

        /** @phpstan-ignore-next-line */
        $record->people()->syncWithoutDetaching([$person->id]);
    }

    /**
     * Attach an opportunity to the record if opportunity_name is provided.
     */
    private function attachOpportunityIfProvided(Model $record, string $teamId, string $creatorId): void
    {
        $opportunityName = $this->data['opportunity_name'] ?? null;

        if (blank($opportunityName)) {
            return;
        }

        $opportunity = Opportunity::firstOrCreate(
            ['name' => trim((string) $opportunityName), 'team_id' => $teamId],
            ['creator_id' => $creatorId, 'creation_source' => CreationSource::IMPORT]
        );

        /** @phpstan-ignore-next-line */
        $record->opportunities()->syncWithoutDetaching([$opportunity->id]);
    }
}
