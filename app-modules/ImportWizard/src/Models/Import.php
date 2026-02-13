<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Models;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Importers\BaseImporter;

final class Import extends Model
{
    use HasUlids;

    protected $guarded = [];

    private ?BaseImporter $importerCache = null;

    protected function casts(): array
    {
        return [
            'entity_type' => ImportEntityType::class,
            'status' => ImportStatus::class,
            'headers' => 'array',
            'column_mappings' => 'array',
            'completed_at' => 'datetime',
            'total_rows' => 'integer',
            'created_rows' => 'integer',
            'updated_rows' => 'integer',
            'skipped_rows' => 'integer',
            'failed_rows' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<FailedImportRow, $this> */
    public function failedRows(): HasMany
    {
        return $this->hasMany(FailedImportRow::class);
    }

    /** @param Builder<Import> $query */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', ImportStatus::Completed);
    }

    /** @param Builder<Import> $query */
    public function scopeFailed(Builder $query): void
    {
        $query->where('status', ImportStatus::Failed);
    }

    /** @param Builder<Import> $query */
    public function scopeForTeam(Builder $query, string $teamId): void
    {
        $query->where('team_id', $teamId);
    }

    public function storagePath(): string
    {
        return storage_path("app/imports/{$this->id}");
    }

    public function getImporter(): BaseImporter
    {
        return $this->importerCache ??= $this->entity_type->importer($this->team_id);
    }

    /**
     * @return Collection<int, ColumnData>
     */
    public function columnMappings(): Collection
    {
        $raw = $this->column_mappings ?? [];
        $importer = $this->getImporter();
        $fields = $importer->allFields();
        $entityLinks = collect($importer->entityLinks());
        $headerOrder = array_flip($this->headers ?? []);

        return ColumnData::collect($raw, Collection::class)
            ->each(function (ColumnData $col) use ($fields, $entityLinks): void {
                if ($col->isFieldMapping()) {
                    $col->importField = $fields->get($col->target);
                } else {
                    $col->entityLinkField = $entityLinks->get($col->entityLink);
                }
            })
            ->sortBy(fn (ColumnData $col): int => $headerOrder[$col->source] ?? PHP_INT_MAX)
            ->values();
    }

    /** @param iterable<int, ColumnData> $mappings */
    public function setColumnMappings(iterable $mappings): void
    {
        $raw = collect($mappings)
            ->map(fn (ColumnData $m): array => $m->toArray())
            ->values()
            ->all();

        $this->update(['column_mappings' => $raw]);
    }

    public function getColumnMapping(string $source): ?ColumnData
    {
        return $this->columnMappings()->firstWhere('source', $source);
    }

    public function updateColumnMapping(string $source, ColumnData $newMapping): void
    {
        $mappings = $this->columnMappings()
            ->map(fn (ColumnData $m): ColumnData => $m->source === $source ? $newMapping : $m);

        $this->setColumnMappings($mappings);
    }

    public function transitionToImporting(): bool
    {
        $lock = Cache::lock("import-{$this->id}-start", 10);

        if (! $lock->get()) {
            return false;
        }

        try {
            $this->refresh();

            if (in_array($this->status, [ImportStatus::Importing, ImportStatus::Completed, ImportStatus::Failed], true)) {
                return false;
            }

            $this->update(['status' => ImportStatus::Importing]);

            return true;
        } finally {
            $lock->release();
        }
    }
}
