<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Enums\CustomFieldType;
use App\Enums\MediaCollection;
use App\Models\Company;
use App\Models\CustomFieldValue;
use App\Models\People;
use Illuminate\Support\Facades\DB;

final readonly class MoveRecordLinks
{
    public function __construct(private MoveMorphPivot $movePivot) {}

    public function execute(Company|People $kept, Company|People $duplicate): void
    {
        $morph = $kept->getMorphClass();

        $this->movePivot->execute('noteables', 'note_id', 'noteable', $kept, $duplicate);
        $this->movePivot->execute('taskables', 'task_id', 'taskable', $kept, $duplicate);
        $this->moveAttachments($morph, $kept, $duplicate);
        $this->repointRecordAttributes($morph, $kept, $duplicate);
    }

    private function moveAttachments(string $morph, Company|People $kept, Company|People $duplicate): void
    {
        DB::table('media')
            ->where('workspace_id', $kept->workspace_id)
            ->where('model_type', $morph)
            ->where('model_id', $duplicate->getKey())
            ->where('collection_name', MediaCollection::Attachments->value)
            ->update(['model_id' => $kept->getKey()]);
    }

    private function repointRecordAttributes(string $morph, Company|People $kept, Company|People $duplicate): void
    {
        DB::update(
            'update custom_field_values set json_value = ('
                .'select jsonb_agg(swapped.element order by swapped.position) from ('
                    .'select element, min(position) as position from ('
                        ."select case when item #>> '{}' = ? then to_jsonb(?::text) else item end as element, position "
                        .'from jsonb_array_elements(custom_field_values.json_value::jsonb) with ordinality as listed(item, position)'
                    .') as renamed group by element'
                .') as swapped'
            .')::json '
            .'where tenant_id = ? and custom_field_id in ('
                .'select id from custom_fields where tenant_id = ? and type = ? and lookup_type = ?'
            .') and exists (select 1 from '.CustomFieldValue::LIST_ELEMENTS.' as element where element = ?)',
            [
                $duplicate->getKey(), $kept->getKey(),
                $kept->workspace_id, $kept->workspace_id, CustomFieldType::RECORD->value, $morph,
                $duplicate->getKey(),
            ],
        );
    }
}
