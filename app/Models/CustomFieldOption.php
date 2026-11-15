<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\StageOptionSettings;
use App\Models\ActivityLog\Activity;
use App\Support\ActivityLog\ActivityValue;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Relaticle\CustomFields\Models\CustomFieldOption as BaseCustomFieldOption;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;
use Relaticle\CustomFields\Models\Scopes\SortOrderScope;
use Relaticle\CustomFields\Models\Scopes\TenantScope;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property StageOptionSettings $settings
 */
#[ScopedBy([TenantScope::class, SortOrderScope::class])]
final class CustomFieldOption extends BaseCustomFieldOption
{
    use HasUlids;
    use LogsActivity;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['settings' => StageOptionSettings::class.':default'];
    }

    protected static function booted(): void
    {
        // The package's option repeater submits only the color, so a save from it would drop the stored celebrate flag.
        self::saving(function (self $option): void {
            $kept = $option->getOriginal('settings');

            if ($option->isDirty('settings') && $kept instanceof StageOptionSettings && $option->settings->celebrate === null && $kept->celebrate !== null) {
                $option->settings = StageOptionSettings::from([...$option->settings->toArray(), 'celebrate' => $kept->celebrate]);
            }
        });
    }

    public function customField(): BelongsTo
    {
        return parent::customField()->withoutGlobalScope(CustomFieldsActivableScope::class);
    }

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
