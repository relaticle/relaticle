<?php

declare(strict_types=1);

namespace App\Queries\Crm;

use App\Enums\CrmEntity;
use App\Models\CustomField;
use Illuminate\Support\Facades\DB;

final readonly class StoredTagsQuery
{
    /** @return list<string> */
    public function forField(CustomField $field, int $limit): array
    {
        $table = CrmEntity::from($field->entity_type)->table();

        $rows = DB::select(
            "select distinct on (lower(btrim(element))) btrim(element) as tag
            from custom_field_values
            cross join lateral jsonb_array_elements_text(case when jsonb_typeof(json_value::jsonb) = 'array' then json_value::jsonb else '[]'::jsonb end) as element
            where tenant_id = ? and custom_field_id = ? and btrim(element) <> ''
            and exists (select 1 from {$table} where {$table}.id = custom_field_values.entity_id and {$table}.deleted_at is null)
            order by lower(btrim(element)), btrim(element)
            limit ?",
            [$field->tenant_id, $field->getKey(), $limit],
        );

        return array_values(array_map(static fn (object $row): string => (string) data_get($row, 'tag'), $rows));
    }
}
