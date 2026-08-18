<?php

declare(strict_types=1);

namespace App\Actions\CustomFields;

use App\Models\CustomField;
use App\Models\CustomFieldValue;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Facades\CustomFieldsType;

/**
 * Find the single record a caller-supplied field/value pair identifies.
 *
 * Matching is case-insensitive for text: the CRM stores email addresses exactly
 * as typed, so `Grace@Navy.MIL` and `grace@navy.mil` must resolve to the same
 * person. Multi-value fields (email, phone, link) live in a JSON array, so the
 * value has to be searched inside that array rather than compared to the column.
 */
final readonly class FindEntityByFieldValue
{
    /**
     * The field data types a match value can be compared against.
     *
     * The value arrives as a string off a JSON body, so a field backed by a
     * boolean, numeric, or date column cannot be compared without a cast the
     * database would reject. Single-choice fields are excluded for a quieter
     * reason: they store option keys, never the label a form submits, so a
     * lookup would silently miss and create a duplicate. Callers validate
     * `match.field` against this list, which turns both into a 422.
     *
     * @var array<int, FieldDataType>
     */
    public const array MATCHABLE_DATA_TYPES = [
        FieldDataType::STRING,
        FieldDataType::TEXT,
        FieldDataType::MULTI_CHOICE,
    ];

    /**
     * @param  class-string<Model>  $modelClass
     * @param  array<int, string>  $nativeColumns  Model columns the caller may match on instead of a custom field code
     */
    public function execute(string $modelClass, string $teamId, string $field, string $value, array $nativeColumns = []): ?Model
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $model = new $modelClass;
        $query = $modelClass::query()->where('team_id', $teamId);

        if (in_array($field, $nativeColumns, true)) {
            $comparison = $this->nativeColumnComparison($field);

            if (! $comparison instanceof Expression) {
                return null;
            }

            $query->where($comparison, mb_strtolower($value));
        } else {
            $entityIds = $this->entityIdsCarryingValue($model->getMorphClass(), $teamId, $field, $value);

            if ($entityIds === []) {
                return null;
            }

            $query->whereIn($model->getKeyName(), $entityIds);
        }

        // Several records can legitimately carry the same value; the caller cannot
        // disambiguate either, so the oldest one always wins.
        return $query->oldest()->orderBy($model->getKeyName())->first();
    }

    /**
     * Case-insensitive comparison for the model columns an upsert may match on.
     *
     * Held as literal SQL per column rather than interpolating the caller's field
     * name, so no identifier taken from the request can reach the query.
     */
    private function nativeColumnComparison(string $column): ?Expression
    {
        return match ($column) {
            'name' => DB::raw('LOWER(name)'),
            default => null,
        };
    }

    /**
     * Case-insensitive comparison for the value columns a match can target.
     *
     * `CustomField::getValueColumn()` stays the authority on which column holds
     * the value; this only maps its answer to literal SQL, so no column name is
     * ever interpolated. A column outside the map means the field type is not
     * matchable and yields no match rather than a query.
     */
    private function caseInsensitiveComparison(string $column): ?Expression
    {
        return match ($column) {
            'string_value' => DB::raw('LOWER(string_value)'),
            'text_value' => DB::raw('LOWER(text_value)'),
            default => null,
        };
    }

    /**
     * @return array<int, string>
     */
    private function entityIdsCarryingValue(string $entityType, string $teamId, string $code, string $value): array
    {
        $customField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $teamId)
            ->where('entity_type', $entityType)
            ->where('code', $code)
            ->active()
            ->first();

        if (! $customField instanceof CustomField) {
            return [];
        }

        // Callers reject an unmatchable type during validation, but this runs
        // first, so refusing here is what keeps a boolean or date column from
        // being compared against a string and raising a driver error.
        if (! in_array(CustomFieldsType::getFieldType($customField->type)?->dataType, self::MATCHABLE_DATA_TYPES, true)) {
            return [];
        }

        $column = $customField->getValueColumn();

        if ($column === 'json_value') {
            return $this->entityIdsFromJsonArray($entityType, $teamId, (string) $customField->getKey(), $value);
        }

        $comparison = $this->caseInsensitiveComparison($column);

        if (! $comparison instanceof Expression) {
            return [];
        }

        return CustomFieldValue::query()
            ->withoutGlobalScopes()
            ->where($this->tenantKey(), $teamId)
            ->where('entity_type', $entityType)
            ->where('custom_field_id', $customField->getKey())
            ->where($comparison, mb_strtolower($value))
            ->pluck('entity_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    /**
     * Expand the stored JSON array into rows and compare each element.
     *
     * Mirrors the driver matrix Relaticle\ImportWizard\Support\EntityLinkResolver
     * already uses for the same lookup; a value written before the field became
     * multi-value can still be a bare scalar, hence the array coercion.
     *
     * @return array<int, string>
     */
    private function entityIdsFromJsonArray(string $entityType, string $teamId, string $customFieldId, string $value): array
    {
        $model = new CustomFieldValue;
        $connection = $model->getConnection();
        $table = $model->getTable();
        $tenantKey = $this->tenantKey();

        $sql = match ($connection->getDriverName()) {
            'sqlite' => "SELECT cfv.entity_id
                FROM {$table} cfv, json_each(
                    CASE WHEN JSON_TYPE(cfv.json_value) = 'array'
                        THEN cfv.json_value
                        ELSE JSON_ARRAY(cfv.json_value)
                    END
                ) je
                WHERE cfv.{$tenantKey} = ?
                  AND cfv.custom_field_id = ?
                  AND cfv.entity_type = ?
                  AND LOWER(CAST(je.value AS TEXT)) = ?",
            'pgsql' => "SELECT cfv.entity_id
                FROM {$table} cfv
                CROSS JOIN LATERAL jsonb_array_elements_text(
                    CASE WHEN jsonb_typeof(cfv.json_value::jsonb) = 'array'
                        THEN cfv.json_value::jsonb
                        ELSE jsonb_build_array(cfv.json_value::jsonb)
                    END
                ) AS je(value)
                WHERE cfv.{$tenantKey} = ?
                  AND cfv.custom_field_id = ?
                  AND cfv.entity_type = ?
                  AND LOWER(je.value) = ?",
            default => "SELECT cfv.entity_id
                FROM {$table} cfv
                JOIN JSON_TABLE(
                    IF(JSON_TYPE(cfv.json_value) = 'ARRAY', cfv.json_value, JSON_ARRAY(cfv.json_value)),
                    '\$[*]' COLUMNS(val TEXT PATH '\$')
                ) AS jt
                WHERE cfv.{$tenantKey} = ?
                  AND cfv.custom_field_id = ?
                  AND cfv.entity_type = ?
                  AND LOWER(jt.val) = ?",
        };

        return collect($connection->select($sql, [$teamId, $customFieldId, $entityType, mb_strtolower($value)]))
            ->pluck('entity_id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();
    }

    private function tenantKey(): string
    {
        return (string) config('custom-fields.database.column_names.tenant_foreign_key');
    }
}
