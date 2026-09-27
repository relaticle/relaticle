<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\ActivityLog\Activity;
use App\Support\ActivityLog\ActivityValue;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomFieldOption as BaseCustomFieldOption;
use Relaticle\CustomFields\Models\Scopes\SortOrderScope;
use Relaticle\CustomFields\Models\Scopes\TenantScope;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[ScopedBy([TenantScope::class, SortOrderScope::class])]
final class CustomFieldOption extends BaseCustomFieldOption
{
    use HasUlids;
    use LogsActivity;

    public function beforeActivityLogged(Activity $activity, string $eventName): void
    {
        if (! $this->customField?->settings?->encrypted) {
            return;
        }

        $changes = $activity->attribute_changes?->toArray() ?? [];

        foreach (['attributes', 'old'] as $side) {
            if (filled($changes[$side]['name'] ?? null)) {
                $changes[$side]['name'] = ActivityValue::REDACTED;
            }
        }

        $activity->attribute_changes = collect($changes);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'settings'])
            ->useAttributeRawValues(['name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('crm')
            ->setDescriptionForEvent(fn (string $eventName): string => $eventName);
    }
}
