<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Models\Scopes\SortOrderScope;
use Relaticle\CustomFields\Models\Scopes\TenantScope;
use Relaticle\CustomFields\Observers\CustomFieldObserver;

/**
 * @property string $tenant_id
 */
#[ScopedBy([TenantScope::class, SortOrderScope::class])]
#[ObservedBy(CustomFieldObserver::class)]
final class CustomField extends BaseCustomField
{
    use HasUlids;
}
