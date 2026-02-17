<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\Support;

use App\Models\CustomField;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Models\CustomFieldValue;
use Relaticle\CustomFields\Support\SafeValueConverter;

final class BulkCustomFieldValueWriter
{
    /** @var array<int, array<string, mixed>> */
    private array $pendingInserts = [];

    public function queue(
        CustomField $customField,
        mixed $value,
        string $entityId,
        string $entityType,
        string $tenantId,
    ): void {
        $valueColumn = CustomFieldValue::getValueColumn($customField->type);
        $safeValue = SafeValueConverter::toDbSafe($value, $customField->type);

        if (is_array($safeValue)) {
            $safeValue = json_encode($safeValue);
        }

        $this->pendingInserts[] = [
            'id' => Str::ulid()->toBase32(),
            'custom_field_id' => $customField->getKey(),
            'entity_id' => $entityId,
            'entity_type' => $entityType,
            'tenant_id' => $tenantId,
            $valueColumn => $safeValue,
        ];
    }

    public function flush(): int
    {
        if ($this->pendingInserts === []) {
            return 0;
        }

        $nullDefaults = array_fill_keys([
            'id', 'custom_field_id', 'entity_id', 'entity_type', 'tenant_id',
            'string_value', 'text_value', 'boolean_value', 'integer_value',
            'float_value', 'date_value', 'datetime_value', 'json_value',
        ], null);

        $normalized = array_map(
            fn (array $row): array => array_merge($nullDefaults, $row),
            $this->pendingInserts,
        );

        $count = count($normalized);
        $tableName = config('custom-fields.database.table_names.custom_field_values', 'custom_field_values');

        foreach (array_chunk($normalized, 500) as $chunk) {
            DB::table($tableName)->insert($chunk);
        }

        $this->pendingInserts = [];

        return $count;
    }

    public function pending(): int
    {
        return count($this->pendingInserts);
    }
}
