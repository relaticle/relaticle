<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports\Concerns;

use App\Models\Note;
use App\Models\Task;

/**
 * @property Note|Task $record
 */
trait SyncsPolymorphicLinks
{
    /**
     * Pending entity IDs to attach via polymorphic relationships.
     *
     * @var array{companies: array<string>, people: array<string>, opportunities: array<string>}
     */
    public array $pendingEntityLinks = [
        'companies' => [],
        'people' => [],
        'opportunities' => [],
    ];

    /**
     * Sync all pending polymorphic entity links to the record.
     *
     * Call this in afterSave() after parent::afterSave().
     */
    protected function syncPendingEntityLinks(): void
    {
        foreach (['companies', 'people', 'opportunities'] as $relation) {
            if ($this->pendingEntityLinks[$relation] !== []) {
                $this->record->{$relation}()->syncWithoutDetaching($this->pendingEntityLinks[$relation]);
            }
        }
    }
}
