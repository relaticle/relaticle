<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\CustomFieldFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\Scopes\SortOrderScope;
use Relaticle\CustomFields\Models\Scopes\TenantScope;
use Relaticle\CustomFields\Observers\CustomFieldObserver;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $tenant_id
 */
#[ScopedBy([TenantScope::class, SortOrderScope::class])]
#[ObservedBy(CustomFieldObserver::class)]
final class CustomField extends BaseCustomField
{
    use HasUlids;
    use LogsActivity;

    /**
     * Whether saving an arbitrary value to this field should promote that value
     * into the field's user-managed option list. True for tags-input; false for
     * email/phone/link, which also accept arbitrary values but own no option list.
     */
    public function promotesValuesToOptions(): bool
    {
        return $this->typeData->acceptsArbitraryValues && ! $this->typeData->withoutUserOptions;
    }

    /** @return CustomFieldFactory */
    protected static function newFactory(): Factory
    {
        return CustomFieldFactory::new();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'active', 'settings'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('crm')
            ->setDescriptionForEvent(fn (string $eventName): string => $eventName);
    }
}
