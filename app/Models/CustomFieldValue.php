<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\CustomFieldValueObserver;
use Database\Factories\CustomFieldValueFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Relaticle\CustomFields\Models\CustomFieldValue as BaseCustomFieldValue;
use Relaticle\CustomFields\Models\Scopes\TenantScope;

#[ObservedBy(CustomFieldValueObserver::class)]
#[ScopedBy([TenantScope::class])]
final class CustomFieldValue extends BaseCustomFieldValue
{
    /** @use HasFactory<CustomFieldValueFactory> */
    use HasFactory;

    use HasUlids;
}
