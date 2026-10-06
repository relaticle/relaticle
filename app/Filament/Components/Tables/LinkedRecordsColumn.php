<?php

declare(strict_types=1);

namespace App\Filament\Components\Tables;

use App\Enums\CrmEntity;
use App\Filament\Components\RecordChip;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use Filament\Facades\Filament;
use Filament\Tables\Columns\Column;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

final class LinkedRecordsColumn extends Column
{
    protected string $view = 'filament.tables.columns.linked-records-column';

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('filament/components/linked-records.label'))
            ->disabledClick();
    }

    /**
     * @return list<array{chip: RecordChip, url: ?string}>
     */
    public function getLinkedRecords(): array
    {
        $owner = $this->getRecord();
        $urls = resolve(CanonicalRecordUrl::class);
        $workspace = Filament::getTenant();
        $linked = [];

        foreach (CrmEntity::linkable() as $entity) {
            $records = $owner instanceof Model ? $owner->getRelationValue($entity->relationName()) : null;

            if (! $records instanceof Collection) {
                continue;
            }

            foreach ($records as $record) {
                $linked[] = [
                    'chip' => RecordChip::forRecord($record),
                    'url' => $workspace instanceof Workspace ? $urls->build($entity, (string) $record->getKey(), $workspace) : null,
                ];
            }
        }

        return $linked;
    }
}
