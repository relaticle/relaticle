<?php

declare(strict_types=1);

namespace App\Support\CustomFields;

use App\Models\CustomField;
use App\Queries\CustomFields\EntitiesByFieldValueQuery;
use Closure;
use Illuminate\Database\Eloquent\Model;

final readonly class RestoreConflictMessage
{
    public function __construct(private EntitiesByFieldValueQuery $entitiesByFieldValue) {}

    /** @param  Closure(Model): ?string  $recordTitle */
    public function for(Model $record, Closure $recordTitle): ?string
    {
        if (! method_exists($record, 'takenUniqueCustomFieldValues')) {
            return null;
        }

        /** @var array{customField: CustomField, value: string}|null $taken */
        $taken = $record->takenUniqueCustomFieldValues()->first();

        if ($taken === null) {
            return null;
        }

        $holder = $this->entitiesByFieldValue->get($record::class, $taken['customField'], [$taken['value']], 1)->first();
        $holderTitle = $holder instanceof Model ? $recordTitle($holder) : null;

        return $holderTitle === null
            ? __('filament/panel.restore_blocked.conflict_with_unknown_holder', ['value' => $taken['value'], 'field' => $taken['customField']->name])
            : __('filament/panel.restore_blocked.conflict', ['value' => $taken['value'], 'field' => $taken['customField']->name, 'holder' => $holderTitle]);
    }
}
