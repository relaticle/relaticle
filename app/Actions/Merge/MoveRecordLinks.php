<?php

declare(strict_types=1);

namespace App\Actions\Merge;

use App\Enums\CustomFieldType;
use App\Enums\MediaCollection;
use App\Models\Company;
use App\Models\CustomFieldValue;
use App\Models\People;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final readonly class MoveRecordLinks
{
    public function execute(Company|People $kept, Company|People $duplicate): void
    {
        $morph = $kept->getMorphClass();

        $this->movePivot('noteables', 'note_id', 'noteable_type', 'noteable_id', $morph, $kept, $duplicate);
        $this->movePivot('taskables', 'task_id', 'taskable_type', 'taskable_id', $morph, $kept, $duplicate);
        $this->moveAttachments($morph, $kept, $duplicate);
        $this->repointRecordAttributes($morph, $kept, $duplicate);
    }

    private function movePivot(string $table, string $itemColumn, string $typeColumn, string $idColumn, string $morph, Company|People $kept, Company|People $duplicate): void
    {
        $linkedTo = fn (Company|People $record): Builder => DB::table($table)->where($typeColumn, $morph)->where($idColumn, $record->getKey());

        $linkedTo($duplicate)
            ->whereIn($itemColumn, $linkedTo($kept)->select($itemColumn))
            ->delete();

        $linkedTo($duplicate)->update([$idColumn => $kept->getKey()]);
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
        $fieldIds = DB::table('custom_fields')
            ->where('tenant_id', $kept->workspace_id)
            ->where('type', CustomFieldType::RECORD->value)
            ->where('lookup_type', $morph)
            ->select('id');

        DB::table('custom_field_values')
            ->where('tenant_id', $kept->workspace_id)
            ->whereIn('custom_field_id', $fieldIds)
            ->whereRaw('exists (select 1 from '.CustomFieldValue::LIST_ELEMENTS.' as element where element = ?)', [$duplicate->getKey()])
            ->chunkById(500, function (Collection $values) use ($kept, $duplicate): void {
                foreach ($values as $value) {
                    $ids = array_map(
                        fn (string $id): string => $id === (string) $duplicate->getKey() ? (string) $kept->getKey() : $id,
                        json_decode((string) $value->json_value, true),
                    );

                    DB::table('custom_field_values')
                        ->where('id', $value->id)
                        ->update(['json_value' => json_encode(array_values(array_unique($ids)))]);
                }
            });
    }
}
