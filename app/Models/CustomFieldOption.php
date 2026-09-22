<?php

declare(strict_types=1);

namespace App\Models;

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

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'settings'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('crm')
            ->setDescriptionForEvent(fn (string $eventName): string => $eventName);
    }
}
