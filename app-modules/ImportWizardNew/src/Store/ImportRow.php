<?php

declare(strict_types=1);

namespace Relaticle\ImportWizardNew\Store;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model for import rows within SQLite storage.
 *
 * This model is designed to be used with ImportStore's dynamic connections.
 * Use ImportStore::query() to get a properly bound query builder.
 *
 * @property int $row_number
 * @property string $data
 * @property string|null $validation
 * @property string|null $corrections
 *
 * @method static Builder<static> withErrors()
 * @method static Builder<static> withCorrections()
 * @method static Builder<static> valid()
 */
final class ImportRow extends Model
{
    protected $table = 'import_rows';

    protected $primaryKey = 'row_number';

    public $incrementing = false;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'row_number',
        'data',
        'validation',
        'corrections',
    ];

    /**
     * @return array<string, mixed>
     */
    public function getDataAttribute(): array
    {
        return json_decode($this->attributes['data'] ?? '{}', true);
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public function setDataAttribute(array $value): void
    {
        $this->attributes['data'] = json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, string>|null
     */
    public function getValidationAttribute(): ?array
    {
        $value = $this->attributes['validation'] ?? null;

        return $value ? json_decode($value, true) : null;
    }

    /**
     * @param  array<string, string>|null  $value
     */
    public function setValidationAttribute(?array $value): void
    {
        $this->attributes['validation'] = $value ? json_encode($value, JSON_UNESCAPED_UNICODE) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCorrectionsAttribute(): ?array
    {
        $value = $this->attributes['corrections'] ?? null;

        return $value ? json_decode($value, true) : null;
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    public function setCorrectionsAttribute(?array $value): void
    {
        $this->attributes['corrections'] = $value ? json_encode($value, JSON_UNESCAPED_UNICODE) : null;
    }

    // =========================================================================
    // QUERY SCOPES
    // =========================================================================

    /**
     * Scope to rows with validation errors.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithErrors(Builder $query): void
    {
        $query->whereNotNull('validation')->where('validation', '!=', '{}');
    }

    /**
     * Scope to rows with corrections applied.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithCorrections(Builder $query): void
    {
        $query->whereNotNull('corrections')->where('corrections', '!=', '{}');
    }

    /**
     * Scope to rows without validation errors.
     *
     * @param  Builder<static>  $query
     */
    public function scopeValid(Builder $query): void
    {
        $query->where(function ($q): void {
            $q->whereNull('validation')->orWhere('validation', '=', '{}');
        });
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /**
     * Check if this row has any validation errors.
     */
    public function hasErrors(): bool
    {
        $validation = $this->validation;

        return $validation !== null && $validation !== [];
    }

    /**
     * Check if this row has any corrections.
     */
    public function hasCorrections(): bool
    {
        $corrections = $this->corrections;

        return $corrections !== null && $corrections !== [];
    }

    /**
     * Get the final value for a column (corrected or original).
     */
    public function getFinalValue(string $column): mixed
    {
        $corrections = $this->corrections;

        if ($corrections !== null && array_key_exists($column, $corrections)) {
            return $corrections[$column];
        }

        return $this->data[$column] ?? null;
    }

    /**
     * Get all final values (with corrections applied).
     *
     * @return array<string, mixed>
     */
    public function getFinalData(): array
    {
        $data = $this->data;
        $corrections = $this->corrections ?? [];

        return array_merge($data, $corrections);
    }
}
