<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Store;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\ImportWizard\Data\RelationshipMatch;
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
 * @property RowMatchAction|null $match_action
 * @property string|null $matched_id
 * @property DataCollection<int, RelationshipMatch>|null $relationships
 *
 * @method static Builder<static> withErrors()
 * @method static Builder<static> withCorrections()
 * @method static Builder<static> valid()
 * @method static Builder<static> toCreate()
 * @method static Builder<static> toUpdate()
 * @method static Builder<static> toSkip()
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
            'match_action' => RowMatchAction::class,
            'relationships' => DataCollection::class.':'.RelationshipMatch::class,
        ];
    }

    // =========================================================================
    // QUERY SCOPES
    // =========================================================================

    /**
     * @param  Builder<static>  $query
     */
    protected function scopeWithErrors(Builder $query): void
    {
        $query->whereNotNull('validation')->where('validation', '!=', '{}');
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function scopeWithCorrections(Builder $query): void
    {
        $query->whereNotNull('corrections')->where('corrections', '!=', '{}');
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function scopeValid(Builder $query): void
    {
        $query->where(function (\Illuminate\Contracts\Database\Query\Builder $q): void {
            $q->whereNull('validation')->orWhere('validation', '=', '{}');
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function scopeToCreate(Builder $query): void
    {
        $query->where('match_action', RowMatchAction::Create->value);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function scopeToUpdate(Builder $query): void
    {
        $query->where('match_action', RowMatchAction::Update->value);
    }

    /**
     * @param  Builder<static>  $query
     */
    protected function scopeToSkip(Builder $query): void
    {
        $query->where('match_action', RowMatchAction::Skip->value);
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
     */
    public function getFinalValue(string $column): mixed
    {
        if ($this->corrections?->has($column)) {
            return $this->corrections->get($column);
        }

        return $this->raw_data->get($column);
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
