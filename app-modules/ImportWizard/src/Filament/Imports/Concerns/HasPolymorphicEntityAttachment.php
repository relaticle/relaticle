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
 */
trait HasPolymorphicEntityAttachment
{
    /**
     * Attach related entities (company, person, opportunity) to the record.
     */
    protected function attachRelatedEntities(): void
    {
        /** @var Model $record */
        $record = $this->record;
        $teamId = $this->import->team_id;
        $creatorId = $this->import->user_id;

        $entities = [
            ['field' => 'company_name', 'model' => Company::class, 'relation' => 'companies'],
            ['field' => 'person_name', 'model' => People::class, 'relation' => 'people'],
            ['field' => 'opportunity_name', 'model' => Opportunity::class, 'relation' => 'opportunities'],
        ];

        foreach ($entities as $entity) {
            $name = $this->data[$entity['field']] ?? null;
            if (blank($name)) {
                continue;
            }

            $model = $entity['model']::firstOrCreate(
                ['name' => trim((string) $name), 'team_id' => $teamId],
                ['creator_id' => $creatorId, 'creation_source' => CreationSource::IMPORT]
            );

            /** @phpstan-ignore-next-line */
            $record->{$entity['relation']}()->syncWithoutDetaching([$model->id]);
        }
    }
}
