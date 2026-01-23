<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Store;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\ImportWizard\Data\RelationshipMatch;
use Relaticle\ImportWizard\Enums\ReviewFilter;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Spatie\LaravelData\DataCollection;

/**
 * Eloquent model for import rows within SQLite storage.
 *
 * This model is designed to be used with ImportStore's dynamic connections.
 * Use ImportStore::query() to get a properly bound query builder.
 *
 * @property int $row_number
 * @property Collection<string, mixed> $raw_data
 * @property Collection<string, string>|null $validation
 * @property Collection<string, mixed>|null $corrections
 * @property Collection<string, bool>|null $skipped
 * @property RowMatchAction|null $match_action
 * @property string|null $matched_id
 * @property DataCollection<int, RelationshipMatch>|null $relationships
 *
 * @method static Builder<static> withErrors(string $column)
 * @method static Builder<static> withCorrections(string $column)
 * @method static Builder<static> withSkipped(string $column)
 * @method static Builder<static> valid()
 * @method static Builder<static> toCreate()
 * @method static Builder<static> toUpdate()
 * @method static Builder<static> toSkip()
 * @method static Builder<static> uniqueValuesFor(string $column)
 * @method static Builder<static> searchValue(string $column, string $search)
 * @method static Builder<static> forFilter(ReviewFilter $filter, string $column)
 */
final class ImportRow extends Model
{
    /** @use HasFactory<Factory<ImportRow>> */
    use HasFactory;

    protected $table = 'import_rows';

    protected $primaryKey = 'row_number';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'row_number',
        'raw_data',
        'validation',
        'corrections',
        'skipped',
        'match_action',
        'matched_id',
        'relationships',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raw_data' => AsCollection::class,
            'validation' => AsCollection::class,
            'corrections' => AsCollection::class,
            'skipped' => AsCollection::class,
            'match_action' => RowMatchAction::class,
            'relationships' => DataCollection::class.':'.RelationshipMatch::class,
        ];
    }

    // =========================================================================
    // QUERY SCOPES
    // =========================================================================

    #[Scope]
    protected function withErrors(Builder $query, string $column): void
    {
        $query->whereRaw('json_extract(validation, ?) IS NOT NULL', ['$.'.$column]);
    }

    #[Scope]
    protected function withCorrections(Builder $query, string $column): void
    {
        $query->whereRaw('json_extract(corrections, ?) IS NOT NULL', ['$.'.$column]);
    }

    #[Scope]
    protected function withSkipped(Builder $query, string $column): void
    {
        $query->whereRaw('json_extract(skipped, ?) IS NOT NULL', ['$.'.$column]);
    }

    #[Scope]
    protected function valid(Builder $query): void
    {
        $query->where(function (\Illuminate\Contracts\Database\Query\Builder $q): void {
            $q->whereNull('validation')->orWhere('validation', '=', '{}');
        });
    }

    #[Scope]
    protected function toCreate(Builder $query): void
    {
        $query->where('match_action', RowMatchAction::Create->value);
    }

    #[Scope]
    protected function toUpdate(Builder $query): void
    {
        $query->where('match_action', RowMatchAction::Update->value);
    }

    #[Scope]
    protected function toSkip(Builder $query): void
    {
        $query->where('match_action', RowMatchAction::Skip->value);
    }

    #[Scope]
    protected function uniqueValuesFor(Builder $query, string $column): void
    {
        $jsonPath = '$.'.$column;

        $query->selectRaw(
            'json_extract(raw_data, ?) as raw_value,
             COUNT(*) as count,
             MAX(json_extract(validation, ?)) as validation_error,
             MAX(json_extract(corrections, ?)) as correction,
             MAX(CASE WHEN json_extract(skipped, ?) IS NOT NULL THEN 1 ELSE 0 END) as is_skipped',
            [$jsonPath, $jsonPath, $jsonPath, $jsonPath]
        )
            ->groupBy('raw_value')
            ->orderByRaw('CASE WHEN raw_value IS NULL OR raw_value = "" THEN 0 ELSE 1 END, count DESC');
    }

    #[Scope]
    protected function searchValue(Builder $query, string $column, string $search): void
    {
        $query->whereRaw('json_extract(raw_data, ?) LIKE ?', ['$.'.$column, '%'.$search.'%']);
    }

    #[Scope]
    protected function forFilter(Builder $query, ReviewFilter $filter, string $column): void
    {
        match ($filter) {
            ReviewFilter::All => null,
            ReviewFilter::NeedsReview => $query->withErrors($column),
            ReviewFilter::Modified => $query->withCorrections($column),
            ReviewFilter::Skipped => $query->withSkipped($column),
        };
    }

    // =========================================================================
    // QUERY METHODS
    // =========================================================================

    /**
     * Count unique values for each filter type in a single query.
     *
     * @param  Builder<static>  $query
     * @return array<string, int>
     */
    public static function countUniqueValuesByFilter(Builder $query, string $column): array
    {
        $jsonPath = '$.'.$column;

        $result = $query
            ->selectRaw('
                COUNT(DISTINCT json_extract(raw_data, ?)) as all_count,
                COUNT(DISTINCT CASE
                    WHEN json_extract(validation, ?) IS NOT NULL
                    THEN json_extract(raw_data, ?)
                END) as needs_review_count,
                COUNT(DISTINCT CASE
                    WHEN json_extract(corrections, ?) IS NOT NULL
                    THEN json_extract(raw_data, ?)
                END) as modified_count,
                COUNT(DISTINCT CASE
                    WHEN json_extract(skipped, ?) IS NOT NULL
                    THEN json_extract(raw_data, ?)
                END) as skipped_count
            ', [$jsonPath, $jsonPath, $jsonPath, $jsonPath, $jsonPath, $jsonPath, $jsonPath])
            ->first();

        return [
            ReviewFilter::All->value => (int) $result->all_count,
            ReviewFilter::NeedsReview->value => (int) $result->needs_review_count,
            ReviewFilter::Modified->value => (int) $result->modified_count,
            ReviewFilter::Skipped->value => (int) $result->skipped_count,
        ];
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    public function hasErrors(): bool
    {
        return $this->validation !== null && $this->validation->isNotEmpty();
    }

    public function hasCorrections(): bool
    {
        return $this->corrections !== null && $this->corrections->isNotEmpty();
    }

    /**
     * Get the final value for a column (corrected or original).
     * Returns null for skipped values.
     */
    public function getFinalValue(string $column): mixed
    {
        if ($this->isValueSkipped($column)) {
            return null;
        }

        if ($this->corrections?->has($column)) {
            return $this->corrections->get($column);
        }

        return $this->raw_data->get($column);
    }

    /**
     * Check if a specific column value is marked as skipped.
     */
    public function isValueSkipped(string $column): bool
    {
        return $this->skipped?->has($column) === true;
    }

    /**
     * Get all final values (with corrections applied).
     *
     * @return array<string, mixed>
     */
    public function getFinalData(): array
    {
        return $this->raw_data->merge($this->corrections ?? [])->all();
    }

    public function isCreate(): bool
    {
        return $this->match_action === RowMatchAction::Create;
    }

    public function isUpdate(): bool
    {
        return $this->match_action === RowMatchAction::Update;
    }

    public function isSkip(): bool
    {
        return $this->match_action === RowMatchAction::Skip;
    }
}
