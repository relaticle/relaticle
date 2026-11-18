<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Data\MergedValues;
use App\Enums\CustomFieldType;
use App\Enums\MediaCollection;
use App\Models\Company;
use App\Models\CustomFieldValue;
use App\Models\People;
use Illuminate\Support\Facades\DB;

final readonly class MoveRecordLinks
{
    public function __construct(private MoveMorphPivot $movePivot) {}

    public function execute(Company|People $merged, Company|People $original, MergedValues $mergedValues): void
    {
        $this->movePivot->execute('noteables', 'note_id', 'noteable', $merged, $original);
        $this->movePivot->execute('taskables', 'task_id', 'taskable', $merged, $original);
        $this->moveAttachments($merged, $original, $mergedValues->attachmentUuids);
        $this->repointRecordAttributes($merged, $original);
    }

    /**
     * @param  list<string>  $referencedUuids
     */
    private function moveAttachments(Company|People $merged, Company|People $original, array $referencedUuids): void
    {
        DB::table('media')
            ->where('workspace_id', $merged->workspace_id)
            ->where('model_type', $merged->getMorphClass())
            ->where('model_id', $original->getKey())
            ->where('collection_name', MediaCollection::Attachments->value)
            ->whereIn('uuid', $referencedUuids)
            ->update(['model_id' => $merged->getKey()]);
    }

    private function repointRecordAttributes(Company|People $merged, Company|People $original): void
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
                $original->getKey(), $merged->getKey(),
                $merged->workspace_id, $merged->workspace_id, CustomFieldType::RECORD->value, $merged->getMorphClass(),
                $original->getKey(),
            ],
        );
    }
}
